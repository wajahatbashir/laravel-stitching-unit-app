<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorPayment;
use Illuminate\Database\Eloquent\Model;

/** Payments to vendors against credit purchases. */
class VendorPaymentController extends CrudController
{
    protected string $model = VendorPayment::class;
    protected string $module = 'vendor_payments';
    protected string $route = 'vendor-payments';
    protected string $title = 'Vendor Payments';
    protected string $singular = 'Vendor payment';
    protected array $with = ['vendor'];
    protected array $searchable = ['vendor.name', 'reference', 'notes'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'amount';
    protected bool $offline = true;

    protected function fields(?Model $m = null): array
    {
        return [
            $this->f('vendor_id', 'Vendor', 'select', ['required' => true, 'options' => opts(Vendor::class)]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('amount', 'Amount paid', 'number', ['required' => true, 'step' => '0.01', 'rules' => 'numeric|min:0.01']),
            $this->f('mode', 'Paid via', 'select', ['required' => true, 'default' => 'bank', 'options' => ['cash' => __('Cash'), 'bank' => __('Bank / app')]]),
            $this->f('reference', 'Reference / transaction ID'),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    /** What each vendor is owed, so the form can show the due amount as soon as a vendor is chosen. */
    protected function formData(?Model $m): array
    {
        $dues = [];
        foreach (Vendor::orderBy('name')->get() as $v) {
            $dues[$v->id] = [
                'due' => $v->payable(),
                'text' => money(abs($v->payable())),
                'bills' => array_map(fn ($b) => [
                    'title' => $b['expense']->title ?: label($b['expense']->type),
                    'left' => money($b['left']),
                    'due' => fmt_date($b['due']),
                    'late' => $b['due']->lt(now()->startOfDay()),
                ], array_slice($v->openBills(), 0, 6)),
            ];
        }

        // editing: the payment already reduces the vendor's due, so add it back to show what was due before it
        return ['dues' => $dues, 'self' => $m ? ['vendor' => $m->vendor_id, 'amount' => (float) $m->amount] : null];
    }

    protected function filters(): array
    {
        return [
            $this->flt('vendor_id', 'Vendor', opts(Vendor::class)),
            $this->flt('mode', 'Paid via', ['cash' => __('Cash'), 'bank' => __('Bank')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Vendor'), 'vendor.name'],
            [__('Via'), 'mode', ['label' => true]],
            [__('Amount'), 'amount', ['money' => true]],
            [__('Reference'), 'reference'],
        ];
    }
}
