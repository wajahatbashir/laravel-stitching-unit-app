<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryController extends CrudController
{
    protected string $model = ExpenseCategory::class;
    protected string $module = 'master';
    protected string $route = 'categories';
    protected string $title = 'Expense Categories';
    protected string $singular = 'Category';
    protected array $searchable = ['name'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    private function typeOptions(): array
    {
        return ['order' => __('Order'), 'unit' => __('Unit'), 'labour' => __('Labour'), 'other' => __('Other')];
    }

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('name', 'Category name', 'text', ['required' => true]),
            $this->f('types', 'Expense types', 'checks', ['required' => true, 'options' => $this->typeOptions(), 'rules' => 'array|min:1',
                'default' => $m ? $m->typeList() : [], 'span' => 2,
                'help' => __('Tick every type this category is used under, e.g. Embellishment for both Order and Labour. One category, one name — no duplicates.')]),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true]),
        ];
    }

    protected function filters(): array
    {
        return [$this->flt('type', 'Type', $this->typeOptions(), ['apply' => fn ($q, $v) => $q->whereJsonContains('types', $v)])];
    }

    protected function columns(): array
    {
        return [
            [__('Name'), 'name'],
            [__('Types'), fn ($r) => collect($r->typeList())->map(fn ($t) => label($t))->implode(', ')],
            [__('Status'), fn ($r) => $r->is_active ? 'active' : 'cancelled', ['badge' => true, 'label' => true]],
        ];
    }

    protected function extraRules(Request $r, ?Model $m): array
    {
        return ['types.*' => 'in:'.implode(',', ExpenseCategory::TYPES)];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        $name = trim((string) $data['name']);
        $dupe = ExpenseCategory::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($m, fn ($q) => $q->whereKeyNot($m->id))->first();
        if ($dupe) {
            throw ValidationException::withMessages(['name' => __('":n" already exists (:t). Open it and tick the extra type instead of creating it twice.',
                ['n' => $dupe->name, 't' => collect($dupe->typeList())->map(fn ($t) => label($t))->implode(', ')])]);
        }
        $data['name'] = $name;

        $types = array_values(array_intersect(ExpenseCategory::TYPES, (array) ($data['types'] ?? [])));
        if ($m) {   // a type cannot be taken away while expenses of that type still use the category
            foreach (array_diff($m->typeList(), $types) as $gone) {
                $n = Expense::withoutGlobalScopes()->where('category_id', $m->id)->where('type', $gone)->count();
                if ($n) {
                    throw ValidationException::withMessages(['types' => __(':n :t expense(s) use this category, so :t cannot be removed from it.', ['n' => $n, 't' => label($gone)])]);
                }
            }
        }
        $data['types'] = $types;
        $data['type'] = $types[0];   // first type, kept for older code / backups

        return $data;
    }

    protected function beforeDelete(Model $m): ?string
    {
        return Expense::withoutGlobalScopes()->where('category_id', $m->id)->exists() ? __('Category is used by expenses; deactivate instead.') : null;
    }
}
