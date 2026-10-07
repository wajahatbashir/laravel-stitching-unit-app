<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategoryController extends CrudController
{
    protected string $model = ExpenseCategory::class;
    protected string $module = 'master';
    protected string $route = 'categories';
    protected string $title = 'Expense Categories';
    protected string $singular = 'Category';
    protected array $searchable = ['name'];
    protected string $orderBy = 'type';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('type', 'Expense type', 'select', ['required' => true, 'options' => ['order' => __('Order'), 'unit' => __('Unit'), 'labour' => __('Labour'), 'other' => __('Other')]]),
            $this->f('name', 'Category name', 'text', ['required' => true]),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true]),
        ];
    }

    protected function filters(): array
    {
        return [$this->flt('type', 'Type', ['order' => __('Order'), 'unit' => __('Unit'), 'labour' => __('Labour'), 'other' => __('Other')])];
    }

    protected function columns(): array
    {
        return [
            [__('Type'), 'type', ['badge' => true, 'label' => true]],
            [__('Name'), 'name'],
            [__('Status'), fn ($r) => $r->is_active ? 'active' : 'cancelled', ['badge' => true, 'label' => true]],
        ];
    }

    protected function beforeDelete(Model $m): ?string
    {
        return \App\Models\Expense::withoutGlobalScopes()->where('category_id', $m->id)->exists() ? __('Category is used by expenses; deactivate instead.') : null;
    }
}
