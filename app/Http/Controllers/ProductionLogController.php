<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ProductionLog;
use App\Models\Worker;
use App\Support\Production;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Pieces that completed a stage (cutting → stitching → finishing → quality check → packing). */
class ProductionLogController extends CrudController
{
    protected string $model = ProductionLog::class;
    protected string $module = 'production';
    protected string $route = 'production-logs';
    protected string $title = 'Production Log';
    protected string $singular = 'Production entry';
    protected array $with = ['order.customer', 'worker'];
    protected array $searchable = ['order.order_no', 'worker.name', 'notes'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'qty';
    protected bool $offline = true;

    private function openOrders(): array
    {
        return opts(Order::class, 'order_no', fn ($q) => $q->whereNotIn('status', ['cancelled', 'delivered'])->orderByDesc('id'));
    }

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('order_id', 'Order', 'select', ['required' => true, 'options' => $this->openOrders()]),
            $this->f('stage', 'Stage completed', 'select', ['required' => true, 'options' => Production::options(), 'default' => 'stitching']),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('qty', 'Pieces completed', 'number', ['required' => true, 'rules' => 'integer|min:1']),
            $this->f('worker_id', 'Worker / team (optional)', 'select', ['options' => opts(Worker::class, 'name', fn ($q) => $q->where('is_active', true))]),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('order_id', 'Order', opts(Order::class, 'order_no')),
            $this->flt('stage', 'Stage', Production::options()),
            $this->flt('worker_id', 'Worker', opts(Worker::class)),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Order'), 'order.order_no'],
            [__('Client'), 'order.customer.name'],
            [__('Stage'), fn ($r) => Production::label($r->stage), ['badge' => true]],
            [__('Pieces'), 'qty'],
            [__('Worker'), 'worker.name'],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        $order = Order::findOrFail($data['order_id']);
        $done = (int) ProductionLog::withoutGlobalScopes()->where('order_id', $order->id)->where('stage', $data['stage'])
            ->when($m, fn ($q) => $q->where('id', '!=', $m->id))->sum('qty');
        $cap = Production::cap($order);
        if ($done + $data['qty'] > $cap) {
            throw ValidationException::withMessages(['qty' => __(':stage would reach :n pieces but order :o is only :q pieces (up to :c allowed). Check the number.',
                ['stage' => Production::label($data['stage']), 'n' => $done + $data['qty'], 'o' => $order->order_no, 'q' => $order->qty, 'c' => $cap])]);
        }

        return $data;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        if ($created && $m->order->status === 'pending') {
            $m->order->update(['status' => 'in_progress']);
        }
    }
}
