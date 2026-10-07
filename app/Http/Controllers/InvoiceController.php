<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Services\Notifier;
use App\Support\Advances;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends CrudController
{
    protected string $model = Invoice::class;
    protected string $module = 'invoices';
    protected string $route = 'invoices';
    protected string $title = 'Invoices';
    protected string $singular = 'Invoice';
    protected array $with = ['customer', 'order'];
    protected array $searchable = ['number', 'customer.name', 'order.order_no'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'total';
    protected bool $offline = true;
    protected string $formView = 'invoices.form';

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('number', 'Invoice number', 'text', ['required' => true, 'default' => Invoice::nextNumber(),
                'rules' => $m ? "unique:invoices,number,{$m->id}" : 'string|max:50']),
            $this->f('date', 'Invoice date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('customer_id', 'Customer', 'select', ['required' => true, 'options' => opts(Customer::class)]),
            $this->f('order_id', 'Order (optional)', 'select', ['options' => opts(Order::class, 'order_no')]),
            $this->f('due_date', 'Due date', 'date'),
            $this->f('discount', 'Discount', 'number', ['step' => '0.01', 'default' => 0, 'rules' => 'nullable|numeric|min:0']),
            $this->f('tax', 'Tax', 'number', ['step' => '0.01', 'default' => 0, 'rules' => 'nullable|numeric|min:0']),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [$this->flt('customer_id', 'Customer', opts(Customer::class))];
    }

    protected function columns(): array
    {
        return [
            [__('Invoice'), 'number'],
            [__('Date'), 'date'],
            [__('Customer'), 'customer.name'],
            [__('Order'), 'order.order_no'],
            [__('Total'), 'total', ['money' => true]],
            [__('Balance'), fn ($r) => $r->balance(), ['money' => true]],
            [__('Status'), fn ($r) => $r->status(), ['badge' => true, 'label' => true]],
        ];
    }

    protected function formData(?Model $m): array
    {
        return [
            'items' => $m ? $m->items->map->only('description', 'qty', 'rate')->all() : [],
            'orders' => Order::get(['id', 'order_no', 'customer_id', 'qty', 'rate', 'collection_name', 'fabric_name'])->keyBy('id'),
            // unused advance per customer (embedded so it also works offline) + what the invoice has already received
            'advances' => Customer::pluck('id')->mapWithKeys(fn ($id) => [$id => Advances::available($id)])->filter(fn ($v) => $v > 0),
            'paidAlready' => $m ? $m->paid() : 0,
        ];
    }

    protected function extraRules(Request $r, ?Model $m): array
    {
        return ['items' => 'required|array|min:1', 'items.*.description' => 'required|string|max:255',
            'items.*.qty' => 'required|numeric|min:0', 'items.*.rate' => 'required|numeric|min:0',
            'apply_advance' => 'nullable|boolean', 'advance_amount' => 'nullable|numeric|min:0'];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        if (! $m && (empty($data['number']) || Invoice::where('number', $data['number'])->exists())) {
            $data['number'] = Invoice::nextNumber();
        }
        $sub = collect($r->input('items'))->sum(fn ($i) => round($i['qty'] * $i['rate'], 2));
        $data['discount'] = $data['discount'] ?? 0;
        $data['tax'] = $data['tax'] ?? 0;
        $data['subtotal'] = $sub;
        $data['total'] = round($sub - $data['discount'] + $data['tax'], 2);

        // "Apply customer advance" checkbox — validate before anything is saved
        if ($r->boolean('apply_advance')) {
            $amt = round((float) $r->input('advance_amount'), 2);
            $free = Advances::available((int) $data['customer_id']);
            $room = $data['total'] - ($m ? $m->paid() : 0);
            $err = match (true) {
                $amt <= 0 => __('Enter the advance amount to deduct.'),
                $amt > $free + 0.004 => __('Only :a advance is available for this customer.', ['a' => money($free)]),
                $amt > $room + 0.004 => __('Advance is more than the amount due on this invoice (:b).', ['b' => money($room)]),
                default => null,
            };
            if ($err) {
                throw ValidationException::withMessages(['advance_amount' => $err]);
            }
        }

        return $data;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        DB::transaction(function () use ($m, $r) {
            $m->items()->delete();
            foreach ($r->input('items') as $i) {
                $m->items()->create(['description' => $i['description'], 'qty' => $i['qty'], 'rate' => $i['rate'],
                    'amount' => round($i['qty'] * $i['rate'], 2)]);
            }
        });
        if ($r->boolean('apply_advance')) {
            Advances::apply($m->fresh(), (float) $r->input('advance_amount'));
        }
        if ($created) {
            Notifier::send('invoice_created', __('Invoice :n', ['n' => $m->number]), $m->customer->name.' — '.money($m->total), route('invoices.show', $m));
        }
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->payments()->withoutGlobalScopes()->exists() ? __('Invoice has payments recorded.') : null;
    }

    public function show($id)
    {
        $this->authorizeAction('view');
        $inv = Invoice::with('customer', 'order', 'items')->findOrFail($id);

        return view('invoices.show', [
            'inv' => $inv,
            'payments' => $inv->payments()->withoutGlobalScopes()->orderBy('date')->get(),
            'allocations' => $inv->allocations()->with('payment')->orderBy('date')->orderBy('id')->get(),
            'advance' => Advances::available($inv->customer_id),
        ]);
    }

    /** Apply part of the customer's advance to an existing invoice. */
    public function applyAdvance(Request $r, $id)
    {
        $this->authorizeAction('edit');
        $inv = Invoice::findOrFail($id);
        $r->validate(['amount' => 'required|numeric|min:0.01']);
        $applied = Advances::apply($inv, (float) $r->input('amount'), 'amount');

        return back()->with('success', __(':a advance applied to :n.', ['a' => money($applied), 'n' => $inv->number]));
    }

    /** Take an applied advance back off the invoice (the money returns to the customer's advance balance). */
    public function removeAllocation($id, $allocation)
    {
        $this->authorizeAction('edit');
        PaymentAllocation::where('invoice_id', $id)->findOrFail($allocation)->delete();

        return back()->with('success', __('Advance removed from invoice; it is available to the customer again.'));
    }

    public function pdf($id)
    {
        $this->authorizeAction('view');
        $inv = Invoice::with('customer', 'order', 'items')->findOrFail($id);

        return Pdf::loadView('invoices.pdf', compact('inv'))->download($inv->number.'.pdf');
    }
}
