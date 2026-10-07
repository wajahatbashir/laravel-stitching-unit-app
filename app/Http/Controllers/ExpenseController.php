<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Currency;
use App\Models\CustomField;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\Vendor;
use App\Services\Notifier;
use App\Services\ReceiptOcr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use App\Support\Upload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExpenseController extends CrudController
{
    protected string $model = Expense::class;
    protected string $module = 'expenses';
    protected string $route = 'expenses';
    protected string $title = 'Expenses';
    protected string $singular = 'Expense';
    protected array $with = ['category', 'order', 'vendor'];
    protected array $searchable = ['title', 'payee', 'notes', 'order.order_no', 'vendor.name'];
    protected ?string $dateColumn = 'date';
    protected ?string $sumColumn = 'base_amount';
    protected bool $offline = true;
    protected string $formView = 'expenses.form';

    private function typeOptions(): array
    {
        return ['order' => __('Order expense'), 'unit' => __('Unit expense'), 'labour' => __('Labour expense'), 'other' => __('Other expense')];
    }

    protected function fields(?Model $m = null): array
    {
        $base = Currency::base();

        return [
            $this->f('type', 'Expense type', 'select', ['required' => true, 'options' => $this->typeOptions(), 'rules' => 'in:order,unit,labour,other']),
            $this->f('category_id', 'Category', 'select', ['options' => ExpenseCategory::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()]),
            $this->f('order_id', 'Order', 'select', ['options' => opts(Order::class, 'order_no')]),
            $this->f('asset_id', 'Related asset (machine / furniture)', 'select', ['options' => opts(Asset::class)]),
            $this->f('vendor_id', 'Vendor', 'select', ['options' => opts(Vendor::class)]),
            $this->f('date', 'Date', 'date', ['required' => true, 'default' => now()->toDateString()]),
            $this->f('title', 'Description'),
            $this->f('payee', 'Paid to'),
            $this->f('qty', 'Quantity', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0']),
            $this->f('rate', 'Rate', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0']),
            $this->f('amount', 'Amount', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0']),
            $this->f('currency_id', 'Currency', 'select', ['options' => Currency::where('is_active', true)->pluck('code', 'id')->all(), 'default' => $base?->id]),
            $this->f('payment_mode', 'Paid via', 'select', ['required' => true, 'default' => 'cash',
                'options' => ['cash' => __('Cash'), 'bank' => __('Bank / app'), 'other' => __('Other'), 'credit' => __('On credit — pay the vendor later')],
                'rules' => 'in:cash,bank,other,credit']),
            $this->f('due_date', 'Bill due date (for credit purchases)', 'date', ['rules' => 'nullable|date', 'help' => __('Only for "On credit". When must this vendor be paid?')]),
            $this->f('receipt', 'Payment receipt', 'file', ['store_as' => 'receipt_path', 'rules' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240']),
            $this->f('ocr_text', 'ocr', 'hidden'),
            $this->f('notes', 'Notes', 'textarea', ['span' => 2]),
        ];
    }

    protected function filters(): array
    {
        return [
            $this->flt('type', 'Type', $this->typeOptions()),
            $this->flt('category_id', 'Category', ExpenseCategory::orderBy('name')->pluck('name', 'id')->all()),
            $this->flt('order_id', 'Order', opts(Order::class, 'order_no')),
            $this->flt('vendor_id', 'Vendor', opts(Vendor::class)),
            $this->flt('payment_mode', 'Paid via', ['cash' => __('Cash'), 'bank' => __('Bank'), 'other' => __('Other'), 'credit' => __('On credit')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Type'), 'type', ['badge' => true, 'label' => true]],
            [__('Category'), 'category.name'],
            [__('Order'), 'order.order_no'],
            [__('Details'), fn ($r) => $r->title ?: ($r->payee ?: '—')],
            [__('Via'), 'payment_mode', ['label' => true]],
            [__('Amount'), 'base_amount', ['money' => true]],
            [__('Receipt'), fn ($r) => $r->receipt_path ? __('View') : '—', ['url' => fn ($r) => $r->receipt_path ? Storage::url($r->receipt_path) : null]],
        ];
    }

    protected function formData(?Model $m): array
    {
        return [
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'type', 'name']),
            'custom' => CustomField::where('entity', 'unit_expense')->where('is_active', true)->orderBy('sort')->get(),
            'preType' => request('type'),
        ];
    }

    protected function extraRules(Request $r, ?Model $m): array
    {
        return ['custom' => 'nullable|array', 'custom.*' => 'nullable', 'ocr_text' => 'nullable|string'];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        $errors = [];
        if ($data['payment_mode'] === 'credit') {
            if (empty($data['vendor_id'])) {
                $errors['vendor_id'] = __('Choose the vendor you bought from — a credit purchase is a bill you owe them.');
            }
        } else {
            $data['due_date'] = null; // a due date only makes sense for credit bills
        }
        if ($data['type'] === 'order' && empty($data['order_id'])) {
            $errors['order_id'] = __('Select the order this expense belongs to.');
        }

        // replacing or removing the saved receipt → the old file is deleted after a successful save (see saved())
        $this->staleReceipt = null;
        $old = $m?->receipt_path;
        $removed = $old && empty($data['receipt_path']) && $r->boolean('remove_receipt');
        if ($old && (! empty($data['receipt_path']) || $removed)) {
            $this->staleReceipt = $old;
        }
        if ($removed) {
            $data['receipt_path'] = null;
        }

        // Receipt → OCR fallback (e.g. uploaded offline, or OCR not run in the browser)
        $path = $removed ? null : ($data['receipt_path'] ?? $m?->receipt_path);
        $text = $removed ? null : ($r->input('ocr_text') ?: $m?->ocr_text);
        if ($path && empty($data['amount']) && ! str_ends_with(strtolower($path), '.pdf')) {
            $ocr = ReceiptOcr::read(Storage::disk('public')->path($path));
            $text = $ocr['text'];
            $data['amount'] = $ocr['amount'];
            $data['date'] ??= $ocr['date'];
            $data['payee'] = $data['payee'] ?? $ocr['payee'];
            $data['title'] = $data['title'] ?? $ocr['title'];
            $data['notes'] = $data['notes'] ?? ($ocr['reference'] ? 'Txn ID: '.$ocr['reference'] : null);
        }
        if (empty($data['amount']) && ! empty($data['qty']) && ! empty($data['rate'])) {
            $data['amount'] = round($data['qty'] * $data['rate'], 2);
        }
        if (empty($data['amount'])) {
            $errors['amount'] = __('Amount is required (could not be read from the receipt).');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $data['ocr_text'] = $text;

        $cur = Currency::find($data['currency_id'] ?? null) ?? Currency::base();
        $data['currency_id'] = $cur?->id;
        $data['exchange_rate'] = $cur?->rate ?? 1;
        $data['base_amount'] = round($data['amount'] * $data['exchange_rate'], 2);

        if (empty($data['title']) && ! empty($data['category_id'])) {
            $data['title'] = ExpenseCategory::find($data['category_id'])?->name;
        }

        // admin-defined custom fields (unit expenses)
        $custom = $m?->custom ?? [];
        foreach (CustomField::where('entity', 'unit_expense')->where('is_active', true)->get() as $cf) {
            if ($cf->type === 'image') {
                if ($r->hasFile("custom.{$cf->key}")) {
                    $custom[$cf->key] = Upload::store($r->file("custom.{$cf->key}"), 'uploads/expenses');
                }
            } elseif ($r->has("custom.{$cf->key}")) {
                $custom[$cf->key] = $r->input("custom.{$cf->key}");
            }
            if ($data['type'] === 'unit' && $cf->required && empty($custom[$cf->key])) {
                throw ValidationException::withMessages(["custom.{$cf->key}" => __(':f is required.', ['f' => $cf->label])]);
            }
        }
        $data['custom'] = $custom ?: null;

        return $data;
    }

    private ?string $staleReceipt = null;

    /** Read-only detail page with a large receipt preview. */
    public function show($id)
    {
        $this->authorizeAction('view');
        $e = Expense::with('category', 'order.customer', 'asset', 'vendor', 'currency')->findOrFail($id);

        return view('expenses.show', [
            'e' => $e,
            'custom' => CustomField::where('entity', 'unit_expense')->orderBy('sort')->get(),
        ]);
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        if ($this->staleReceipt) {
            Storage::disk('public')->delete($this->staleReceipt);
            $this->staleReceipt = null;
        }
        if ($created) {
            Notifier::send('expense_added', __('Expense: :a', ['a' => money($m->base_amount)]),
                label($m->type).' — '.($m->title ?: $m->payee), route('expenses.edit', $m));
        }
    }

    /** AJAX: read a receipt image and suggest amount / date / payee. */
    public function ocr(Request $r)
    {
        $this->authorizeAction('create');
        $r->validate(['receipt' => 'required|image|max:10240']);
        $tmp = $r->file('receipt')->store('tmp', 'local');
        try {
            return response()->json(ReceiptOcr::read(Storage::disk('local')->path($tmp)));
        } finally {
            Storage::disk('local')->delete($tmp);
        }
    }
}
