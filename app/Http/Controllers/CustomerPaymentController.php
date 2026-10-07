<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Notifier;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class CustomerPaymentController extends CrudController
{
    protected string $model = CustomerPayment::class;
    protected string $module = 'customer_payments';
    protected string $route = 'customer-payments';
    protected string $title = 'Customer Payments';
    protected string $singular = 'Payment';
    protected array $with = ['customer', 'invoice', 'allocations.invoice'];
    protected array $searchable = ['customer.name', 'reference'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'amount';
    protected bool $offline = true;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('customer_id', 'Customer', 'select', ['required' => true, 'options' => opts(Customer::class)]),
            $this->f('invoice_id', 'Against invoice (optional)', 'select', ['options' => opts(Invoice::class, 'number'), 'help' => __('Leave empty to record an ADVANCE (money on account). You can deduct it from invoices later, on the invoice or while creating one.')]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('amount', 'Amount received', 'number', ['required' => true, 'step' => '0.01', 'rules' => 'numeric|min:0.01']),
            $this->f('mode', 'Received via', 'select', ['required' => true, 'default' => 'bank', 'options' => ['cash' => __('Cash'), 'bank' => __('Bank')]]),
            $this->f('reference', 'Reference / cheque no.'),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('customer_id', 'Customer', opts(Customer::class)),
            $this->flt('mode', 'Via', ['cash' => __('Cash'), 'bank' => __('Bank')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Customer'), 'customer.name'],
            [__('Invoice'), 'invoice.number'],
            [__('Via'), 'mode', ['label' => true]],
            [__('Amount'), 'amount', ['money' => true]],
            [__('Applied to'), fn ($r) => $r->invoice_id ? ($r->invoice?->number ?: '—') : ($r->allocations->pluck('invoice.number')->filter()->unique()->implode(', ') ?: '—')],
            [__('Advance unused'), fn ($r) => $r->invoice_id ? '—' : money($r->unapplied())],
            [__('Reference'), 'reference'],
        ];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        // an advance that is already applied to invoices must stay consistent
        $applied = $m && ! $m->invoice_id ? $m->applied() : 0;
        if ($applied > 0) {
            if (! empty($data['invoice_id'])) {
                throw ValidationException::withMessages(['invoice_id' => __('This advance is already applied to invoices; remove those first.')]);
            }
            if ($data['amount'] + 0.004 < $applied) {
                throw ValidationException::withMessages(['amount' => __('Amount cannot be less than the :a already applied to invoices.', ['a' => money($applied)])]);
            }
        }

        return $data;
    }

    protected function beforeDelete(Model $m): ?string
    {
        return $m->allocations()->exists() ? __('This advance is applied to invoices. Remove it from those invoices first.') : null;
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        if ($created) {
            Notifier::send('customer_payment', __('Received :a from :c', ['a' => money($m->amount), 'c' => $m->customer->name]), fmt_date($m->date));
        }
    }
}
