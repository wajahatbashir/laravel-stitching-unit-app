<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Order;
use App\Services\Notifier;
use App\Support\Production;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Partial deliveries: one challan per batch of an order sent to the brand. */
class DeliveryController extends CrudController
{
    protected string $model = Delivery::class;
    protected string $module = 'deliveries';
    protected string $route = 'deliveries';
    protected string $title = 'Deliveries';
    protected string $singular = 'Delivery';
    protected array $with = ['order.customer'];
    protected array $searchable = ['challan_no', 'order.order_no', 'order.customer.name', 'received_by', 'vehicle'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'qty';
    protected bool $offline = true;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('challan_no', 'Challan number', 'text', ['required' => (bool) $m, 'default' => Delivery::nextNumber(),
                'rules' => $m ? "unique:deliveries,challan_no,{$m->id}" : 'string|max:50']),
            $this->f('date', 'Delivery date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('order_id', 'Order', 'select', ['required' => true,
                'options' => opts(Order::class, 'order_no', fn ($q) => $q->whereNotIn('status', ['cancelled'])->orderByDesc('id'))]),
            $this->f('qty', 'Pieces in this delivery', 'number', ['required' => true, 'rules' => 'integer|min:1']),
            $this->f('vehicle', 'Vehicle / rider'),
            $this->f('received_by', 'Received by (name at the brand)'),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [$this->flt('order_id', 'Order', opts(Order::class, 'order_no'))];
    }

    protected function columns(): array
    {
        return [
            [__('Challan'), 'challan_no'],
            [__('Date'), 'date'],
            [__('Order'), 'order.order_no'],
            [__('Client'), 'order.customer.name'],
            [__('Pieces'), 'qty'],
            [__('Vehicle'), 'vehicle'],
            [__('Received by'), 'received_by'],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        if (! $m && (empty($data['challan_no']) || Delivery::where('challan_no', $data['challan_no'])->exists())) {
            $data['challan_no'] = Delivery::nextNumber(); // offline forms may carry a stale number
        }
        $order = Order::findOrFail($data['order_id']);
        $sent = (int) Delivery::where('order_id', $order->id)->when($m, fn ($q) => $q->where('id', '!=', $m->id))->sum('qty');
        $cap = Production::cap($order);
        if ($sent + $data['qty'] > $cap) {
            throw ValidationException::withMessages(['qty' => __('Order :o is :q pieces and :s were already delivered; this would make :n (up to :c allowed).',
                ['o' => $order->order_no, 'q' => $order->qty, 's' => $sent, 'n' => $sent + $data['qty'], 'c' => $cap])]);
        }

        return $data;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        $this->syncOrderStatus($m->order);
        if ($created) {
            Notifier::send('delivery_created', __('Delivery :c: :q pcs of :o', ['c' => $m->challan_no, 'q' => $m->qty, 'o' => $m->order->order_no]),
                $m->order->customer->name, route('deliveries.show', $m, false));
        }
    }

    public function destroy(Request $r, $id)
    {
        $order = Delivery::findOrFail($id)->order;
        $res = parent::destroy($r, $id);
        $this->syncOrderStatus($order->fresh());

        return $res;
    }

    /** Fully delivered → status "delivered"; first delivery of a pending order → "in progress"; un-delivering reopens it. */
    private function syncOrderStatus(Order $o): void
    {
        $sent = (int) Delivery::where('order_id', $o->id)->sum('qty');
        if ($o->qty > 0 && $sent >= $o->qty) {
            $o->update(['status' => 'delivered']);
        } elseif ($o->status === 'delivered') {
            $o->update(['status' => 'in_progress']);
        } elseif ($sent > 0 && $o->status === 'pending') {
            $o->update(['status' => 'in_progress']);
        }
    }

    public function show($id)
    {
        $this->authorizeAction('view');
        $d = Delivery::with('order.customer')->findOrFail($id);

        return view('deliveries.show', ['d' => $d, 'sum' => Production::summary($d->order)]);
    }

    public function pdf($id)
    {
        $this->authorizeAction('view');
        $d = Delivery::with('order.customer')->findOrFail($id);
        $before = (int) Delivery::where('order_id', $d->order_id)->where('id', '<', $d->id)->sum('qty');

        return Pdf::loadView('deliveries.pdf', ['d' => $d, 'before' => $before])->download($d->challan_no.'.pdf');
    }
}
