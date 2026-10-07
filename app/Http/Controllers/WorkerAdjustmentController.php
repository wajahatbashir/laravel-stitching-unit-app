<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Models\WorkerAdjustment;
use Illuminate\Database\Eloquent\Model;

/** Deductions and bonuses on a worker's earnings (rejects create deductions automatically). */
class WorkerAdjustmentController extends CrudController
{
    protected string $model = WorkerAdjustment::class;
    protected string $module = 'worker_payments';
    protected string $route = 'worker-adjustments';
    protected string $title = 'Deductions & Bonuses';
    protected string $singular = 'Adjustment';
    protected array $with = ['worker', 'reject'];
    protected array $searchable = ['worker.name', 'reason'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'amount';
    protected bool $offline = true;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('worker_id', 'Worker', 'select', ['required' => true, 'options' => opts(Worker::class)]),
            $this->f('type', 'Type', 'select', ['required' => true, 'default' => 'deduction',
                'options' => ['deduction' => __('Deduction (reduces what we owe)'), 'bonus' => __('Bonus (adds to what we owe)')]]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('amount', 'Amount', 'number', ['required' => true, 'step' => '0.01', 'rules' => 'numeric|min:0.01']),
            $this->f('reason', 'Reason', 'text', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('worker_id', 'Worker', opts(Worker::class)),
            $this->flt('type', 'Type', ['deduction' => __('Deduction'), 'bonus' => __('Bonus')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Worker'), 'worker.name'],
            [__('Type'), 'type', ['badge' => true, 'label' => true]],
            [__('Amount'), 'amount', ['money' => true]],
            [__('Reason'), 'reason'],
            [__('From reject'), fn ($r) => $r->reject_id ? '#'.$r->reject_id : '—'],
        ];
    }
}
