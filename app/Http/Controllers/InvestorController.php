<?php

namespace App\Http\Controllers;

use App\Models\Investor;
use Illuminate\Database\Eloquent\Model;

class InvestorController extends CrudController
{
    protected string $model = Investor::class;
    protected string $module = 'investors';
    protected string $route = 'investors';
    protected string $title = 'Investors';
    protected string $singular = 'Investor';
    protected array $searchable = ['name', 'phone'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('name', 'Investor name', 'text', ['required' => true]),
            $this->f('phone', 'Phone', 'tel'),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
            $this->f('is_active', 'Active', 'checkbox', ['default' => true]),
        ];
    }

    protected function columns(): array
    {
        $net = fn ($r) => (float) $r->investments()->withoutGlobalScopes()->where('type', 'investment')->sum('amount')
            - (float) $r->investments()->withoutGlobalScopes()->where('type', 'withdrawal')->sum('amount');

        return [
            [__('Name'), 'name'],
            [__('Phone'), 'phone'],
            [__('Net invested'), $net, ['money' => true]],
        ];
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->investments()->withoutGlobalScopes()->exists() ? __('Investor has investments.') : null;
    }
}
