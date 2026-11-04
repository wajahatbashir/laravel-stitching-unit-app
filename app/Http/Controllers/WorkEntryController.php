<?php

namespace App\Http\Controllers;

use App\Models\GarmentType;
use App\Models\Order;
use App\Models\Worker;
use App\Models\WorkEntry;
use App\Support\WorkPayStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkEntryController extends CrudController
{
    protected string $model = WorkEntry::class;
    protected string $module = 'work_entries';
    protected string $route = 'work-entries';
    protected string $title = 'Work Entries';
    protected string $singular = 'Work entry';
    protected array $with = ['worker', 'order', 'garmentType'];
    protected array $searchable = ['worker.name', 'order.order_no', 'notes'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'amount';
    protected bool $offline = true;
    protected string $formView = 'work_entries.form';

    protected function fields(?Model $m = null): array
    {
        $fields = [
            $this->f('worker_id', 'Worker', 'select', ['required' => true, 'options' => opts(Worker::class, 'name', fn ($q) => $q->where('is_active', true))]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('order_id', 'Order', 'select', ['options' => opts(Order::class, 'order_no')]),
            $this->f('garment_type_id', 'Garment', 'select', ['required' => true, 'options' => opts(GarmentType::class, 'name', fn ($q) => $q->where('is_active', true))]),
            $this->f('qty', 'Pieces', 'number', ['required' => true, 'rules' => 'integer|min:1']),
            $this->f('rate', 'Rate per piece', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0',
                'help' => __('Auto-filled from the rate card; change if needed')]),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];

        // closing (locking) an entry is for admins with the "close" permission; others never see or send this field
        if (auth()->user()?->can('work_entries.close')) {
            $fields[] = $this->f('status', 'Entry status', 'select', ['default' => 'open', 'rules' => 'nullable|in:open,closed',
                'options' => ['open' => __('Open — can be edited'), 'closed' => __('Closed — locked (only a Super Admin can change it)')],
                'help' => __('Close an entry once it is settled. A closed entry cannot be edited or deleted except by a Super Admin.')]);
        }

        return $fields;
    }

    protected function filters(): array
    {
        return [
            $this->flt('worker_id', 'Worker', opts(Worker::class)),
            $this->flt('order_id', 'Order', opts(Order::class, 'order_no')),
            $this->flt('garment_type_id', 'Garment', opts(GarmentType::class)),
            $this->flt('status', 'Status', ['open' => __('Open'), 'closed' => __('Closed (locked)')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Worker'), 'worker.name'],
            [__('Order'), 'order.order_no'],
            [__('Garment'), 'garmentType.name'],
            [__('Pieces'), 'qty'],
            [__('Rate'), 'rate', ['money' => true]],
            [__('Amount'), 'amount', ['money' => true]],
            [__('Payment'), fn ($r) => (($p = $this->payMap()[$r->id] ?? null)) ? WorkPayStatus::label($p['status']) : '—',
                ['badge' => true, 'badge_key' => fn ($r) => $this->payMap()[$r->id]['status'] ?? '']],
            [__('Status'), fn ($r) => $r->status === 'closed' ? '🔒 '.__('Closed') : __('Open')],
        ];
    }

    protected function formData(?Model $m): array
    {
        // rate card map so the form can auto-fill the rate, also while offline
        $default = GarmentType::pluck('default_rate', 'id');
        $map = [];
        foreach (Worker::with('rates')->get() as $w) {
            $over = $w->rates->pluck('rate', 'garment_type_id');
            $map[$w->id] = $default->map(fn ($d, $gid) => (float) ($over[$gid] ?? $d))->all();
        }

        return ['rateMap' => $map, 'pay' => $m ? ($this->payMap()[$m->id] ?? null) : null];
    }

    private ?array $pay = null;

    /** work-entry id → Paid / Partly paid / Unpaid (payments clear what is owed oldest first), computed once per request */
    private function payMap(): array
    {
        return $this->pay ??= WorkPayStatus::map();
    }

    private function isSuper(): bool
    {
        return (bool) auth()->user()?->hasRole('Super Admin');
    }

    /** A closed entry is read-only for everyone except a Super Admin. */
    public function edit($id)
    {
        $this->authorizeAction('edit');
        $m = WorkEntry::findOrFail($id);
        if ($m->isClosed() && ! $this->isSuper()) {
            return redirect()->route('work-entries.index')->withErrors(['edit' => __('This work entry is closed (locked). Only a Super Admin can change it.')]);
        }

        return $this->form($m);
    }

    protected function frozenIds($rows): array
    {
        return $this->isSuper() ? [] : $rows->where('status', 'closed')->pluck('id')->all();
    }

    protected function warnIds($rows): array
    {
        return $rows->filter(fn ($e) => ($this->payMap()[$e->id]['status'] ?? 'unpaid') !== 'unpaid')->pluck('id')->all();
    }

    protected function bulkActions(): ?array
    {
        $u = auth()->user();
        $actions = [];
        if ($u?->can('work_entries.close')) {
            $actions[] = ['value' => 'lock', 'label' => __('Lock selected'), 'confirm' => __('Lock the selected entries? Only a Super Admin can change or delete them afterwards.')];
        }
        if ($this->isSuper()) {
            $actions[] = ['value' => 'unlock', 'label' => __('Re-open selected'), 'confirm' => __('Re-open the selected entries so they can be edited again?')];
        }

        return $actions ? ['route' => route('work-entries.bulk'), 'actions' => $actions] : null;
    }

    /** Grid action: lock (admins with the close permission) or re-open (Super Admin) the ticked entries. */
    public function bulk(Request $r)
    {
        $d = $r->validate(['action' => 'required|in:lock,unlock', 'ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        abort_unless($d['action'] === 'lock' ? $r->user()->can('work_entries.close') : $this->isSuper(), 403);

        $target = $d['action'] === 'lock' ? 'closed' : 'open';
        $done = $skipped = 0;
        foreach (WorkEntry::whereIn('id', array_unique($d['ids']))->get() as $e) {   // the normal own-records scope still applies
            if ($e->status === $target) {
                $skipped++;
                continue;
            }
            try {
                $e->status = $target;
                $e->save();
                $done++;
            } catch (ValidationException) {
                $skipped++;                                                          // e.g. the entry sits in a closed month
            }
        }

        $msg = $d['action'] === 'lock' ? __(':n work entries locked.', ['n' => $done]) : __(':n work entries re-opened.', ['n' => $done]);
        if ($skipped) {
            $msg .= ' '.__(':s skipped (already in that state, or in a closed month).', ['s' => $skipped]);
        }

        return back()->with('success', $msg);
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        if (! isset($data['rate']) || $data['rate'] === '') {
            $data['rate'] = Worker::findOrFail($data['worker_id'])->rateFor((int) $data['garment_type_id']);
        }
        $data['amount'] = round($data['qty'] * $data['rate'], 2);

        return $data;
    }
}
