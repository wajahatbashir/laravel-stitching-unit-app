<?php

namespace App\Http\Controllers;

use App\Models\Investment;
use App\Models\Investor;
use Illuminate\Database\Eloquent\Model;

class InvestmentController extends CrudController
{
    protected string $model = Investment::class;
    protected string $module = 'investments';
    protected string $route = 'investments';
    protected string $title = 'Investments';
    protected string $singular = 'Investment';
    protected array $with = ['investor'];
    protected array $searchable = ['reference', 'investor.name'];
    protected ?string $dateColumn = 'date';
    protected bool $offline = true;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('investor_id', 'Investor', 'select', ['required' => true, 'options' => opts(Investor::class)]),
            $this->f('type', 'Type', 'select', ['required' => true, 'default' => 'investment',
                'options' => ['investment' => __('Investment'), 'withdrawal' => __('Withdrawal / Return')]]),
            $this->f('mode', 'Where', 'select', ['required' => true, 'default' => 'bank',
                'options' => ['bank' => __('Cash deposit in bank'), 'cash' => __('Cash in hand')]]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('amount', 'Amount', 'number', ['required' => true, 'rules' => 'numeric|min:0.01', 'step' => '0.01']),
            $this->f('reference', 'Reference / slip no.'),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('investor_id', 'Investor', opts(Investor::class)),
            $this->flt('type', 'Type', ['investment' => __('Investment'), 'withdrawal' => __('Withdrawal')]),
            $this->flt('mode', 'Where', ['bank' => __('Bank'), 'cash' => __('Cash')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Investor'), 'investor.name'],
            [__('Type'), 'type', ['badge' => true, 'label' => true]],
            [__('Where'), 'mode', ['label' => true]],
            [__('Amount'), 'amount', ['money' => true]],
            [__('Reference'), 'reference'],
        ];
    }
}
