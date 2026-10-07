<?php

namespace App\Http\Controllers;

use App\Exports\TableExport;
use App\Support\PeriodLock;
use App\Support\Upload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Generic list / create / edit / delete engine driven by fields(), columns() and filters().
 * Each module controller only declares what is different.
 */
abstract class CrudController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;
    protected string $module;       // permission module, e.g. "orders"
    protected string $route;        // route name prefix, e.g. "orders"
    protected string $title;        // plural title
    protected string $singular;
    protected array $with = [];
    protected array $searchable = []; // columns or relation.column
    protected ?string $dateColumn = null;
    protected string $orderBy = 'id';
    protected string $orderDir = 'desc';
    protected ?string $sumColumn = null;
    protected bool $offline = false;  // create form queues offline
    protected string $formView = 'crud.form';

    abstract protected function fields(?Model $m = null): array;

    abstract protected function columns(): array;

    protected function filters(): array
    {
        return [];
    }

    /** Field definition shorthand. Options: required, options, rules, default, span, step, help, like. */
    protected function f(string $name, string $label, string $type = 'text', array $o = []): array
    {
        return ['name' => $name, 'label' => __($label), 'type' => $type] + $o;
    }

    /** Filter definition shorthand (select unless type given). */
    protected function flt(string $name, string $label, array $options = [], array $o = []): array
    {
        return ['name' => $name, 'label' => __($label), 'type' => $options ? 'select' : 'text', 'options' => $options] + $o;
    }

    // ---- hooks -------------------------------------------------------
    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        return $data;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
    }

    protected function beforeDelete(Model $m): ?string
    {
        return null; // return message to block deletion
    }

    protected function extraRules(Request $r, ?Model $m): array
    {
        return [];
    }

    protected function formData(?Model $m): array
    {
        return [];
    }

    // ---- helpers -----------------------------------------------------
    protected function usesUuid(): bool
    {
        return property_exists($this->model, 'hasUuid') && ($this->model)::$hasUuid;
    }

    protected function authorizeAction(string $action): void
    {
        abort_unless(auth()->user()?->can("{$this->module}.$action"), 403);
    }

    protected function baseQuery(Request $r): Builder
    {
        return ($this->model)::query()->with($this->with);
    }

    protected function query(Request $r): Builder
    {
        $q = $this->baseQuery($r);

        if ($s = trim((string) $r->query('q'))) {
            $q->where(function (Builder $w) use ($s) {
                foreach ($this->searchable as $c) {
                    if (str_contains($c, '.')) {
                        [$rel, $col] = explode('.', $c, 2);
                        $w->orWhereHas($rel, fn ($x) => $x->where($col, 'like', "%$s%"));
                    } else {
                        $w->orWhere($c, 'like', "%$s%");
                    }
                }
            });
        }

        foreach ($this->filters() as $f) {
            $v = $r->query($f['name']);
            if ($v !== null && $v !== '') {
                empty($f['like'])
                    ? $q->where($f['column'] ?? $f['name'], $v)
                    : $q->where($f['column'] ?? $f['name'], 'like', "%$v%");
            }
        }

        if ($this->dateColumn) {
            $col = ($this->model)::make()->getTable().'.'.$this->dateColumn;
            if ($r->filled('date_from')) {
                $q->whereDate($col, '>=', $r->query('date_from'));
            }
            if ($r->filled('date_to')) {
                $q->whereDate($col, '<=', $r->query('date_to'));
            }
        }

        return $q->orderBy($this->orderBy, $this->orderDir);
    }

    // ---- actions -----------------------------------------------------
    public function index(Request $r)
    {
        $this->authorizeAction('view');
        $q = $this->query($r);

        if ($fmt = $r->query('export')) {
            return $this->export($q->get(), $fmt);
        }

        $sum = $this->sumColumn ? (clone $q)->reorder()->sum($this->sumColumn) : null;
        $rows = $q->paginate(20)->withQueryString();

        return view('crud.index', [
            'title' => $this->title,
            'route' => $this->route,
            'module' => $this->module,
            'rows' => $rows,
            'columns' => $this->columns(),
            'filters' => $this->filters(),
            'hasDate' => (bool) $this->dateColumn,
            'hasSearch' => (bool) $this->searchable,
            'sum' => $sum,
            'sumMoney' => in_array($this->sumColumn, ['amount', 'cost', 'total', 'base_amount'], true),
            // rows in a closed month show a padlock instead of the delete button
            'lockedIds' => in_array(\App\Models\Concerns\LocksPeriod::class, class_uses_recursive($this->model), true)
                ? $rows->getCollection()->filter(fn ($m) => PeriodLock::isLocked($m))->pluck('id')->all() : [],
            'canShow' => method_exists($this, 'show'),
            'canEdit' => auth()->user()->can("{$this->module}.edit"),
            'canCreate' => auth()->user()->can("{$this->module}.create"),
            'canDelete' => auth()->user()->can("{$this->module}.delete"),
        ]);
    }

    public function create()
    {
        $this->authorizeAction('create');
        return $this->form(null);
    }

    public function edit($id)
    {
        $this->authorizeAction('edit');
        return $this->form(($this->model)::findOrFail($id));
    }

    protected function form(?Model $m)
    {
        return view($this->formView, [
            'title' => $m ? __('Edit').' '.$this->singular : __('New').' '.$this->singular,
            'route' => $this->route,
            'model' => $m,
            'fields' => $this->fields($m),
            'offline' => $this->offline && ! $m,
            'action' => $m ? route("{$this->route}.update", $m) : route("{$this->route}.store"),
            'extra' => $this->formData($m),
        ]);
    }

    public function store(Request $r)
    {
        $this->authorizeAction('create');

        if ($this->usesUuid()) {
            if ($r->filled('uuid') && ($this->model)::withoutGlobalScopes()->where('uuid', $r->input('uuid'))->exists()) {
                return $this->done($r, 'Already saved.');
            }
        }

        $this->withPeriodOverride($r, function () use ($r) {
            $m = $this->persist($r, null);
            $this->saved($m, $r, true);
        });

        return $this->done($r, __(':x saved.', ['x' => $this->singular]));
    }

    public function update(Request $r, $id)
    {
        $this->authorizeAction('edit');
        $m = ($this->model)::findOrFail($id);
        $this->withPeriodOverride($r, function () use ($r, $m) {
            $this->persist($r, $m);
            $this->saved($m->fresh(), $r, false);
        });

        return $this->done($r, __(':x updated.', ['x' => $this->singular]));
    }

    /**
     * Month close: a closed month rejects changes unless an admin with "period.override" gives a written reason.
     * The override lasts only for this request and is written to the audit log by PeriodLock.
     */
    protected function withPeriodOverride(Request $r, \Closure $work)
    {
        $reason = trim((string) $r->input('override_reason'));
        if ($reason !== '') {
            if (! $r->user()->can('period.override')) {
                throw ValidationException::withMessages(['period' => __('You are not allowed to override a closed month.')]);
            }
            if (mb_strlen($reason) < 5) {
                throw ValidationException::withMessages(['override_reason' => __('Write a reason (at least 5 characters).')]);
            }
            PeriodLock::allow($reason);
        }
        try {
            return $work();
        } finally {
            PeriodLock::allow(null);
        }
    }

    public function destroy(Request $r, $id)
    {
        $this->authorizeAction('delete');
        $m = ($this->model)::findOrFail($id);
        if ($msg = $this->beforeDelete($m)) {
            return back()->withErrors(['delete' => $msg]);
        }
        if (method_exists($m, 'attachments')) {
            $m->attachments->each(function ($a) {
                Storage::disk('public')->delete($a->path);
                $a->delete();
            });
        }
        $m->delete();

        return $this->done($r, __(':x deleted.', ['x' => $this->singular]));
    }

    protected function done(Request $r, string $msg)
    {
        if ($r->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $msg]);
        }

        if ($r->boolean('again')) {
            return redirect()->route("{$this->route}.create")->with('success', $msg);
        }

        return redirect()->route("{$this->route}.index")->with('success', $msg);
    }

    protected function persist(Request $r, ?Model $m): Model
    {
        $fields = collect($this->fields($m));
        $rules = [];
        foreach ($fields as $f) {
            if ($f['type'] === 'files') {
                $rules['files'] = 'nullable|array|max:20';
                $rules['files.*'] = 'nullable|file|mimes:jpg,jpeg,png,webp,gif,pdf|max:10240';
                continue;
            }
            $rule = $f['rules'] ?? ($f['type'] === 'file' ? 'nullable|file|max:10240' : 'nullable');
            if (! empty($f['required']) && ! str_contains($rule, 'required')) {
                $rule = "required|$rule";
            }
            $rules[$f['name']] = $rule;
        }
        $data = $r->validate($rules + $this->extraRules($r, $m));
        $data = array_intersect_key($data, $rules);

        unset($data['files']);
        foreach ($fields as $f) {
            $n = $f['name'];
            if ($f['type'] === 'files') {
                continue;
            }
            if ($f['type'] === 'checkbox') {
                $data[$n] = $r->boolean($n);
            } elseif ($f['type'] === 'file') {
                unset($data[$n]);
                if ($r->hasFile($n)) {
                    $data[$f['store_as'] ?? $n] = Upload::store($r->file($n), "uploads/{$this->module}");
                }
            } elseif (array_key_exists($n, $data) && $data[$n] === null && ! empty($f['default_null'])) {
                $data[$n] = null;
            }
        }

        if (! $m && $this->usesUuid()) {
            $data['uuid'] = $r->input('uuid') ?: null;
        }

        $data = $this->prepare($data, $m, $r);

        if ($m) {
            $m->update($data);
            return $m;
        }

        return ($this->model)::create($data);
    }

    /** Delete the attachments ticked "remove" in the form (only ones that belong to this record), then store new uploads. */
    protected function saveFiles(Model $m, Request $r): void
    {
        $ids = array_filter((array) $r->input('remove_attachments', []), 'is_numeric');
        if ($ids) {
            $m->attachments()->whereIn('id', $ids)->get()->each(function ($a) {
                Storage::disk('public')->delete($a->path);
                $a->delete();
            });
        }

        foreach ((array) $r->file('files', []) as $file) {
            $m->attachments()->create([
                'path' => Upload::store($file, "uploads/{$this->module}"),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'created_by' => auth()->id(),
            ]);
        }
    }

    // ---- export ------------------------------------------------------
    protected function export($rows, string $fmt)
    {
        $cols = array_values(array_filter($this->columns(), fn ($c) => empty(($c[2] ?? [])['noexport'])));
        $head = array_map(fn ($c) => $c[0], $cols);
        $data = $rows->map(fn ($row) => array_map(fn ($c) => cell_text($row, $c), $cols))->all();

        return TableExport::respond($this->title, $head, $data, $fmt);
    }
}
