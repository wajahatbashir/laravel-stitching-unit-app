<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class CurrencyController extends CrudController
{
    protected string $model = Currency::class;
    protected string $module = 'master';
    protected string $route = 'currencies';
    protected string $title = 'Currencies';
    protected string $singular = 'Currency';
    protected string $orderBy = 'code';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('code', 'Code (PKR, USD…)', 'text', ['required' => true, 'rules' => 'string|max:5|unique:currencies,code'.($m ? ",{$m->id}" : '')]),
            $this->f('name', 'Name', 'text', ['required' => true]),
            $this->f('symbol', 'Symbol', 'text', ['required' => true]),
            $this->f('rate', 'Rate (1 unit = ? base currency)', 'number', ['required' => true, 'step' => '0.000001', 'rules' => 'numeric|min:0.000001', 'default' => 1]),
            $this->f('is_base', 'Base currency (reports are in this)', 'checkbox'),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Code'), 'code'],
            [__('Name'), 'name'],
            [__('Rate'), 'rate'],
            [__('Base'), fn ($r) => $r->is_base ? 'yes' : '—', ['label' => true]],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        if (! empty($data['is_base'])) {
            $data['rate'] = 1;
        }

        return $data;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        if ($m->is_base) {
            Currency::where('id', '!=', $m->id)->update(['is_base' => false]);
        }
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->is_base || \App\Models\Expense::withoutGlobalScopes()->where('currency_id', $m->id)->exists()
            ? __('Currency is the base or is used by expenses.') : null;
    }
}
