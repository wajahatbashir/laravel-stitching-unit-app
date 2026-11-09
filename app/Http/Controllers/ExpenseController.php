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
    protected array $with = ['category', 'order', 'vendor', 'sharedOrders'];
    protected array $searchable = ['title', 'payee', 'notes', 'order.order_no', 'sharedOrders.order_no', 'vendor.name'];
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
            $this->f('payment_mode', 'Paid via', 'select', ['required' => true, 'default' => 'bank',
                'options' => ['cash' => __('Cash'), 'bank' => __('Bank / app'), 'other' => __('Other'), 'credit' => __('On credit — pay the vendor later'),
                    'partial' => __('Partly on credit — part paid now, rest later')],
                'rules' => 'in:cash,bank,other,credit,partial']),
            $this->f('paid_now', 'Amount paid now', 'number', ['step' => '0.01', 'rules' => 'nullable|numeric|min:0']),
            $this->f('paid_via', 'Paid now via', 'select', ['default' => 'cash', 'options' => ['cash' => __('Cash'), 'bank' => __('Bank / app'), 'other' => __('Other')], 'rules' => 'nullable|in:cash,bank,other']),
            $this->f('due_date', 'Bill due date (for the credit part)', 'date', ['rules' => 'nullable|date', 'help' => __('Only for credit purchases. When must this vendor be paid?')]),
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
            $this->flt('order_id', 'Order', opts(Order::class, 'order_no'), ['apply' => fn ($q, $v) => $q->where(fn ($w) => $w->where('order_id', $v)
                ->orWhereIn('id', \Illuminate\Support\Facades\DB::table('expense_orders')->where('order_id', $v)->select('expense_id')))]),
            $this->flt('vendor_id', 'Vendor', opts(Vendor::class)),
            $this->flt('payment_mode', 'Paid via', ['cash' => __('Cash'), 'bank' => __('Bank'), 'other' => __('Other'), 'credit' => __('On credit'), 'partial' => __('Partly on credit')]),
        ];
    }

    protected function columns(): array
    {
        return [
            [__('Date'), 'date'],
            [__('Type'), 'type', ['badge' => true, 'label' => true]],
            [__('Category'), 'category.name'],
            [__('Order'), fn ($e) => $e->orderNumbers() ?: '—'],
            [__('Details'), fn ($r) => $r->title ?: ($r->payee ?: '—')],
            [__('Via'), fn ($r) => $r->payment_mode === 'partial' ? __('Partly on credit') : label($r->payment_mode)],
            [__('Amount'), 'base_amount', ['money' => true]],
            [__('Credit status'), fn ($r) => (($b = $this->billMap()[$r->id] ?? null)) ? \App\Support\VendorBills::label($b['status']) : '—',
                ['badge' => true, 'badge_key' => fn ($r) => $this->billMap()[$r->id]['status'] ?? '']],
            [__('Receipt'), fn ($r) => $r->receipt_path ? __('View') : '—', ['url' => fn ($r) => $r->receipt_path ? Storage::url($r->receipt_path) : null]],
        ];
    }

    protected function formData(?Model $m): array
    {
        return [
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'type', 'types', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'types' => $c->typeList()])->values(),
            'custom' => CustomField::where('entity', 'unit_expense')->where('is_active', true)->orderBy('sort')->get(),
            'preType' => request('type'),
            'orderPicker' => $this->orderPicker($m),
        ];
    }

    /** In-progress orders offered in the "which orders is this for?" picker, plus what is already ticked (edit / ?order_id= link). */
    private function orderPicker(?Model $m): array
    {
        $picked = collect(old('order_ids', $m ? ($m->order_id ? [$m->order_id] : $m->sharedOrders()->pluck('orders.id')->all()) : array_filter([request('order_id')])))
            ->map(fn ($i) => (int) $i)->all();
        $shares = old('shares', $m ? $m->sharedOrders->mapWithKeys(fn ($o) => [$o->id => (string) $o->pivot->amount])->all() : []);

        $orders = Order::with('customer', 'brand')
            ->where(fn ($q) => $q->where('status', 'in_progress')->orWhereIn('id', $picked))   // only in-progress orders (+ ones already on this expense)
            ->orderBy('order_no')->get()
            ->map(fn ($o) => ['id' => $o->id, 'no' => $o->order_no, 'customer' => $o->customer?->name, 'what' => $o->brand_collection, 'qty' => (int) $o->qty,
                'status' => $o->status, 'statusLabel' => label($o->status)])->all();

        return ['orders' => $orders, 'picked' => $picked, 'shares' => (object) $shares];
    }

    protected function extraRules(Request $r, ?Model $m): array
    {
        return ['order_ids' => 'nullable|array|max:30', 'order_ids.*' => 'integer|exists:orders,id', 'shares' => 'nullable|array', 'shares.*' => 'nullable|numeric|min:0',
            'custom' => 'nullable|array', 'custom.*' => 'nullable', 'ocr_text' => 'nullable|string'];
    }

    protected function prepare(array $data, ?Model $m, Request $r): array
    {
        $errors = [];
        $mode = $data['payment_mode'];
        if (in_array($mode, Expense::CREDIT_MODES, true)) {
            if (empty($data['vendor_id'])) {
                $errors['vendor_id'] = __('Choose the vendor you bought from — a credit purchase is a bill you owe them.');
            }
            if ($mode === 'credit') {
                $data['payee'] = null; // nobody has been paid yet
            }
        } else {
            $data['due_date'] = null; // a due date only makes sense for credit bills
        }
        if ($mode !== 'partial') {
            $data['paid_now'] = null;
            $data['paid_via'] = null;
        }
        // Order expense: one order (expenses.order_id) or several (order_id empty, one share row per order)
        $this->pendingShares = [];
        $orderIds = [];
        if ($data['type'] === 'order') {
            $orderIds = collect((array) $r->input('order_ids'))->push($data['order_id'] ?? null)->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
            if (! $orderIds) {
                $errors['order_id'] = __('Select the order this expense belongs to.');
            }
        }
        $data['order_id'] = count($orderIds) === 1 ? $orderIds[0] : null;   // other types never keep an order

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
        if ($mode === 'partial') {
            $paidNow = (float) ($data['paid_now'] ?? 0);
            if ($paidNow <= 0 || (! empty($data['amount']) && $paidNow >= (float) $data['amount'])) {
                $errors['paid_now'] = __('Enter how much was paid now: more than 0 and less than the total amount. (Fully paid → choose Cash or Bank; nothing paid → On credit.)');
            }
            $data['paid_via'] = $data['paid_via'] ?: 'cash';
        }
        if (count($orderIds) > 1 && ! empty($data['amount'])) {
            $shares = [];
            foreach ($orderIds as $id) {
                $shares[$id] = round((float) $r->input("shares.$id", 0), 2);
            }
            if (abs(array_sum($shares) - (float) $data['amount']) > 0.01) {
                $errors['order_ids'] = __('The amounts given to the orders (:a) must add up to the expense amount (:b).',
                    ['a' => number_format(array_sum($shares), 2), 'b' => number_format((float) $data['amount'], 2)]);
            } elseif (in_array(0.0, $shares, true)) {
                $errors['order_ids'] = __('Every selected order needs an amount; untick the orders that did not use this material.');
            }
            $this->pendingShares = $shares;
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

    /** @var array<int,float> order id => its share in the expense currency (only when 2+ orders) */
    private array $pendingShares = [];

    private ?array $bills = null;

    /** expense id → payment status of its credit bill (computed once per request). */
    private function billMap(): array
    {
        return $this->bills ??= \App\Support\VendorBills::map();
    }

    /** Read-only detail page with a large receipt preview. */
    public function show($id)
    {
        $this->authorizeAction('view');
        $e = Expense::with('category', 'order.customer', 'sharedOrders.customer', 'asset', 'vendor', 'currency')->findOrFail($id);

        return view('expenses.show', [
            'e' => $e,
            'bill' => $e->vendor_id ? (\App\Support\VendorBills::map($e->vendor_id)[$e->id] ?? null) : null,
            'custom' => CustomField::where('entity', 'unit_expense')->orderBy('sort')->get(),
        ]);
    }

    protected function saved(Model $m, Request $r, bool $created): void
    {
        if ($this->staleReceipt) {
            Storage::disk('public')->delete($this->staleReceipt);
            $this->staleReceipt = null;
        }
        $this->syncShares($m);
        if ($created) {
            Notifier::send('expense_added', __('Expense: :a', ['a' => money($m->base_amount)]),
                label($m->type).' — '.($m->title ?: $m->payee), route('expenses.edit', $m));
        }
    }

    /** Replace the per-order shares of a shared purchase; the base-currency shares add up to the expense exactly. */
    private function syncShares(Model $m): void
    {
        $m->sharedOrders()->detach();
        if (! $this->pendingShares) {
            return;
        }
        $rate = (float) ($m->exchange_rate ?: 1);
        $left = (float) $m->base_amount;
        $last = array_key_last($this->pendingShares);
        $rows = [];
        foreach ($this->pendingShares as $orderId => $amount) {
            $base = $orderId === $last ? round($left, 2) : round($amount * $rate, 2);
            $left -= $base;
            $rows[$orderId] = ['amount' => $amount, 'base_amount' => $base];
        }
        $m->sharedOrders()->attach($rows);
        $this->pendingShares = [];
    }

    /** Export (PDF / Excel) ends with the total of each expense type and the grand total of what was exported. */
    protected function exportTotals($rows): array
    {
        $by = $rows->groupBy('type');
        $out = [];
        foreach (Expense::TYPES as $t) {
            if ($by->has($t)) {
                $out[label($t).' ('.$by[$t]->count().')'] = money($by[$t]->sum('base_amount'));
            }
        }
        $out[__('Total expenses').' ('.$rows->count().')'] = money($rows->sum('base_amount'));

        return $out;
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
