<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ProductionReject;
use App\Models\Worker;
use App\Models\WorkerAdjustment;
use App\Support\Production;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Rejected pieces and pieces sent back for rework; optionally deducted from the responsible worker's pay. */
class ProductionRejectController extends CrudController
{
    protected string $model = ProductionReject::class;
    protected string $module = 'production';
    protected string $route = 'production-rejects';
    protected string $title = 'Rejects & Rework';
    protected string $singular = 'Reject / rework';
    protected array $with = ['order.customer', 'worker', 'adjustment'];
    protected array $searchable = ['order.order_no', 'worker.name', 'reason'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'qty';
    protected bool $offline = true;

    private ?float $deduct = null;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('order_id', 'Order', 'select', ['required' => true, 'options' => opts(Order::class, 'order_no', fn ($q) => $q->orderByDesc('id'))]),
            $this->f('kind', 'What happened', 'select', ['required' => true, 'default' => 'reject',
                'options' => ['reject' => __('Rejected — pieces lost / unusable'), 'rework' => __('Sent back for rework — will be fixed')]]),
            $this->f('stage', 'Found at stage', 'select', ['options' => Production::options()]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('qty', 'Pieces', 'number', ['required' => true, 'rules' => 'integer|min:1']),
            $this->f('worker_id', 'Responsible worker (optional)', 'select', ['options' => opts(Worker::class, 'name', fn ($q) => $q->where('is_active', true))]),
            $this->f('reason', 'Reason (stain, wrong size, loose stitching…)'),
            $this->f('deduct_amount', 'Deduct from worker pay (amount)', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0',
                'help' => __('Optional. Leave empty for no deduction. Typical: pieces × the worker rate for that garment. Needs a responsible worker.'),
                'value' => $m?->adjustment?->amount]),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('order_id', 'Order', opts(Order::class, 'order_no')),
            $this->flt('kind', 'Type', ['reject' => __('Rejected'), 'rework' => __('Rework')]),
            $this->flt('stage', 'Stage', Production::options()),
            $this->flt('worker_id', 'Worker', opts(Worker::class)),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Order'), 'order.order_no'],
            [__('Type'), 'kind', ['badge' => true, 'label' => true]],
            [__('Stage'), fn ($r) => $r->stage ? Production::label($r->stage) : '—'],
            [__('Pieces'), 'qty'],
            [__('Worker'), 'worker.name'],
            [__('Reason'), 'reason'],
            [__('Deducted'), fn ($r) => $r->adjustment ? money($r->adjustment->amount) : '—'],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        $this->deduct = isset($data['deduct_amount']) && $data['deduct_amount'] !== '' ? (float) $data['deduct_amount'] : null;
        unset($data['deduct_amount']);
        if ($this->deduct && ! $r->user()->can('worker_payments.create')) {
            throw ValidationException::withMessages(['deduct_amount' => __('You are not allowed to deduct from worker pay.')]);
        }
        if ($this->deduct && empty($data['worker_id'])) {
            throw ValidationException::withMessages(['worker_id' => __('Choose the responsible worker to deduct from their pay.')]);
        }

        return $data;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        $adj = $m->adjustment;
        if ($this->deduct && $this->deduct > 0 && $m->worker_id) {
            $reason = __('Rejected :q pcs on :o', ['q' => $m->qty, 'o' => $m->order->order_no]).($m->stage ? ' ('.Production::label($m->stage).')' : '').($m->reason ? ' — '.$m->reason : '');
            if ($m->kind === 'rework') {
                $reason = str_replace(__('Rejected'), __('Rework'), $reason);
            }
            $attrs = ['worker_id' => $m->worker_id, 'type' => 'deduction', 'date' => $m->date, 'amount' => $this->deduct, 'reason' => $reason];
            $adj ? $adj->update($attrs) : WorkerAdjustment::create($attrs + ['reject_id' => $m->id]);
        } elseif ($adj) {
            $adj->delete(); // deduction removed from the form → remove it from the worker's ledger too
        }
    }
}
