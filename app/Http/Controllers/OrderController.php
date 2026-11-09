<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class OrderController extends CrudController
{
    protected string $model = Order::class;
    protected string $module = 'orders';
    protected string $route = 'orders';
    protected string $title = 'Orders';
    protected string $singular = 'Order';
    protected array $with = ['customer', 'brand'];
    protected array $searchable = ['order_no', 'collection_name', 'fabric_name', 'customer.name', 'brand.name'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'qty';
    protected bool $offline = true;

    private function statuses(): array
    {
        return collect(Order::STATUSES)->mapWithKeys(fn ($s) => [$s => label($s)])->all();
    }

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('order_no', 'Order number', 'text', ['required' => (bool) $m,
                'help' => $m ? null : __('Fills in by itself when you choose the customer (e.g. SWA-0007). You can type your own.'),
                'rules' => $m ? "unique:orders,order_no,{$m->id}" : 'nullable|string|max:50']),
            $this->f('date', 'Order date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('customer_id', 'Customer', 'select', ['required' => true, 'options' => opts(Customer::class)]),
            $this->f('brand_id', 'Brand', 'select', ['rules' => 'nullable|exists:brands,id', 'help' => __('Pick the brand. Missing one? Add it under Admin → Brands.'),
                'options' => opts(Brand::class, 'name', fn ($q) => $q->where(fn ($w) => $w->where('is_active', true)->when($m?->brand_id, fn ($x, $id) => $x->orWhere('id', $id))))]),
            $this->f('collection_name', 'Collection', 'text', ['help' => __('Type the collection name, e.g. Eid Luxe')]),
            $this->f('fabric_name', 'Fabric name'),
            $this->f('qty', 'Quantity (pcs)', 'number', ['required' => true, 'rules' => 'integer|min:1']),
            $this->f('rate', 'Rate per piece (billing)', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0']),
            $this->f('amount', 'Total amount', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0',
                'help' => __('Leave empty to use quantity × rate')]),
            $this->f('due_date', 'Due date', 'date'),
            $this->f('status', 'Status', 'select', ['required' => true, 'default' => 'pending', 'options' => $this->statuses()]),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
            $this->f('files', 'Attachments (designs, samples)', 'files', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('customer_id', 'Customer', opts(Customer::class)),
            $this->flt('status', 'Status', $this->statuses()),
            $this->flt('brand_id', 'Brand', opts(Brand::class)),
            $this->flt('collection_name', 'Collection', [], ['like' => true]),
            $this->flt('fabric_name', 'Fabric', [], ['like' => true]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Order #'), 'order_no'],
            [__('Date'), 'date'],
            [__('Customer'), 'customer.name'],
            [__('Brand'), 'brand.name'],
            [__('Collection'), 'collection_name'],
            [__('Fabric'), 'fabric_name'],
            [__('Qty'), 'qty'],
            [__('Amount'), 'amount', ['money' => true]],
            [__('Due'), 'due_date'],
            [__('Status'), 'status', ['badge' => true, 'label' => true]],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        if (! $m && (empty($data['order_no']) || Order::withoutGlobalScopes()->where('order_no', $data['order_no'])->exists())) {
            $data['order_no'] = Order::nextNumber((int) ($data['customer_id'] ?? 0) ?: null); // empty, or an offline-created form with a stale number
        }
        $data['rate'] = $data['rate'] ?? 0;
        if (empty($data['amount'])) {
            $data['amount'] = round(($data['qty'] ?? 0) * $data['rate'], 2);
        }

        return $data;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        $this->saveFiles($m, $r);
        if ($created) {
            Notifier::send('order_created', __('New order :n', ['n' => $m->order_no]),
                $m->customer->name.' — '.$m->qty.' pcs', route('orders.show', $m));
        }
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->expenses()->withoutGlobalScopes()->exists() || $m->sharedExpenses()->exists() || $m->workEntries()->withoutGlobalScopes()->exists() || $m->invoices()->exists()
            ? __('Order has expenses, work entries or invoices; set status to cancelled instead.') : null;
    }

    /** GET /orders/next-number?customer_id= — used by the order form to fill the number when a customer is picked. */
    public function nextNumber(Request $r)
    {
        $this->authorizeAction('create');

        return response()->json(['order_no' => Order::nextNumber($r->integer('customer_id') ?: null)]);
    }

    public function show($id)
    {
        $this->authorizeAction('view');
        $o = Order::with('customer', 'attachments', 'invoices')->findOrFail($id);
        // own purchases + this order's share of purchases bought together with other orders
        $expenses = $o->expenses()->withoutGlobalScopes()->with('category')->get()
            ->merge($o->sharedExpenses()->with('category', 'sharedOrders')->get()->each(function ($e) {
                $e->setAttribute('share_total', $e->base_amount);
                $e->setAttribute('base_amount', $e->pivot->base_amount);
                $e->setAttribute('shared_with', $e->sharedOrders->sortBy('order_no')->pluck('order_no')->implode(', '));
            }))->sortBy('date')->values();
        $work = $o->workEntries()->withoutGlobalScopes()->with('worker', 'garmentType')->orderBy('date')->get();
        $material = $o->materialCost();
        $labour = (float) $work->sum('amount');

        return view('orders.show', compact('o', 'expenses', 'work', 'material', 'labour') + [
            'prod' => \App\Support\Production::summary($o),
            'billed' => (float) $o->amount,
            'profit' => (float) $o->amount - $material - $labour,
        ]);
    }
}
