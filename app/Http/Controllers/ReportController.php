<?php

namespace App\Http\Controllers;

use App\Exports\TableExport;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Vendor;
use App\Models\Worker;
use App\Support\Finance;
use App\Support\Ledger;
use App\Support\Perms;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index()
    {
        $reports = collect(Perms::REPORTS)->filter(fn ($l, $k) => auth()->user()->can("reports.$k"));

        return view('reports.index', compact('reports'));
    }

    public function show(Request $r, string $key)
    {
        abort_unless(isset(Perms::REPORTS[$key]) && auth()->user()->can("reports.$key"), 403);
        $rep = $this->{'r_'.$key}($r);
        $rep['title'] = __(Perms::REPORTS[$key]);

        if ($fmt = $r->query('export')) {
            $rows = array_map(fn ($row) => array_map(fn ($c) => is_float($c) || is_int($c) ? $c : (string) $c, $row), $rep['rows']);

            return TableExport::respond($rep['title'], $rep['head'], $rows, $fmt, $rep['totals'] ?? []);
        }

        return view('reports.show', ['rep' => $rep, 'key' => $key, 'values' => $r->query()]);
    }

    private function dates(Request $r): array
    {
        return [$r->query('date_from') ?: null, $r->query('date_to') ?: null];
    }

    private function inRange($q, Request $r, string $col = 'date')
    {
        [$f, $t] = $this->dates($r);

        return $q->when($f, fn ($x) => $x->whereDate($col, '>=', $f))->when($t, fn ($x) => $x->whereDate($col, '<=', $t));
    }

    // ---- reports -----------------------------------------------------
    private function r_orders(Request $r): array
    {
        $q = $this->inRange(Order::with('customer'), $r)
            ->when($r->customer_id, fn ($x, $v) => $x->where('customer_id', $v))
            ->when($r->status, fn ($x, $v) => $x->where('status', $v))
            ->when($r->collection_name, fn ($x, $v) => $x->where('collection_name', 'like', "%$v%"))
            ->when($r->fabric_name, fn ($x, $v) => $x->where('fabric_name', 'like', "%$v%"))
            ->orderBy('date');
        $rows = [];
        $t = ['qty' => 0, 'billed' => 0, 'mat' => 0, 'lab' => 0];
        foreach ($q->get() as $o) {
            $mat = $o->materialCost();
            $lab = $o->labourCost();
            $cost = $mat + $lab;
            $rows[] = [$o->order_no, fmt_date($o->date), $o->customer->name, $o->collection_name ?: '—', $o->fabric_name ?: '—', $o->qty,
                (float) $o->amount, $mat, $lab, $cost, round($o->amount - $cost, 2), $o->qty ? round($cost / $o->qty, 2) : 0];
            $t['qty'] += $o->qty; $t['billed'] += $o->amount; $t['mat'] += $mat; $t['lab'] += $lab;
        }

        return [
            'head' => [__('Order #'), __('Date'), __('Client'), __('Collection'), __('Fabric'), __('Qty'), __('Billed'), __('Material cost'), __('Labour cost'), __('Total cost'), __('Profit'), __('Cost / pc')],
            'rows' => $rows, 'date' => true,
            'filters' => [
                $this->sel('customer_id', 'Client', opts(Customer::class)),
                $this->sel('status', 'Status', collect(Order::STATUSES)->mapWithKeys(fn ($s) => [$s => label($s)])->all()),
                ['name' => 'collection_name', 'label' => __('Collection')], ['name' => 'fabric_name', 'label' => __('Fabric')],
            ],
            'totals' => [__('Pieces') => $t['qty'], __('Billed') => $t['billed'], __('Material cost') => $t['mat'], __('Labour cost') => $t['lab'],
                __('Profit') => round($t['billed'] - $t['mat'] - $t['lab'], 2)],
            'moneyCols' => [6, 7, 8, 9, 10, 11],
        ];
    }

    private function r_expenses(Request $r): array
    {
        $q = $this->inRange(Expense::withoutGlobalScopes()->with('category', 'order', 'vendor'), $r)
            ->when($r->type, fn ($x, $v) => $x->where('type', $v))
            ->when($r->category_id, fn ($x, $v) => $x->where('category_id', $v))
            ->when($r->order_id, fn ($x, $v) => $x->where('order_id', $v))
            ->when($r->vendor_id, fn ($x, $v) => $x->where('vendor_id', $v))
            ->when($r->payment_mode, fn ($x, $v) => $x->where('payment_mode', $v))
            ->orderBy('date');
        $rows = [];
        $by = [];
        $sum = 0;
        foreach ($q->get() as $e) {
            $rows[] = [fmt_date($e->date), label($e->type), $e->category?->name ?: '—', $e->order?->order_no ?: '—', $e->title ?: '—',
                $e->vendor?->name ?: ($e->payee ?: '—'), label($e->payment_mode), (float) $e->base_amount];
            $by[$e->type] = ($by[$e->type] ?? 0) + $e->base_amount;
            $sum += $e->base_amount;
        }
        $totals = [];
        foreach ($by as $k => $v) {
            $totals[label($k)] = $v;
        }
        $totals[__('Total')] = $sum;

        return [
            'head' => [__('Date'), __('Type'), __('Category'), __('Order'), __('Details'), __('Vendor / Payee'), __('Via'), __('Amount')],
            'rows' => $rows, 'date' => true, 'totals' => $totals, 'moneyCols' => [7],
            'filters' => [
                $this->sel('type', 'Type', collect(Expense::TYPES)->mapWithKeys(fn ($s) => [$s => label($s)])->all()),
                $this->sel('category_id', 'Category', ExpenseCategory::orderBy('name')->pluck('name', 'id')->all()),
                $this->sel('order_id', 'Order', opts(Order::class, 'order_no')),
                $this->sel('vendor_id', 'Vendor', opts(Vendor::class)),
                $this->sel('payment_mode', 'Paid via', ['cash' => __('Cash'), 'bank' => __('Bank'), 'other' => __('Other')]),
            ],
        ];
    }

    private function r_labour(Request $r): array
    {
        [$f, $t] = $this->dates($r);
        $rows = [];
        $te = $tp = $tb = 0;
        $q = Worker::query()->when($r->type, fn ($x, $v) => $x->where('type', $v))->when($r->worker_id, fn ($x, $v) => $x->where('id', $v))->orderBy('name');
        foreach ($q->get() as $w) {
            $e = $w->earned($f, $t);
            $p = $w->paid($f, $t);
            $bal = $w->balance();
            $rows[] = [$w->name, label($w->type), label($w->pay_cycle), $e, $p, $bal, $bal > 0 ? __('Payable') : ($bal < 0 ? __('Advance held') : __('Settled'))];
            $te += $e; $tp += $p; $tb += $bal;
        }

        return [
            'head' => [__('Worker'), __('Type'), __('Cycle'), __('Earned (period)'), __('Paid (period)'), __('Balance now'), __('Status')],
            'rows' => $rows, 'date' => true, 'moneyCols' => [3, 4, 5],
            'filters' => [
                $this->sel('worker_id', 'Worker', opts(Worker::class)),
                $this->sel('type', 'Type', ['freelancer' => __('Freelancer'), 'salaried' => __('Employee')]),
            ],
            'totals' => [__('Earned') => $te, __('Paid') => $tp, __('Net payable') => $tb],
        ];
    }

    private function r_worker_statement(Request $r): array
    {
        $rows = [];
        $totals = [];
        if ($r->worker_id && ($w = Worker::find($r->worker_id))) {
            [$f, $t] = $this->dates($r);
            $l = Ledger::build($w, $f, $t);
            foreach ($l['lines'] as $x) {
                $rows[] = [fmt_date($x['date']), $x['desc'], $x['earned'] ?: '', $x['paid'] ?: '', $x['balance']];
            }
            $totals = [__('Opening') => $l['opening'], __('Earned') => $l['earned'], __('Paid') => $l['paid'], __('Closing balance') => $l['closing']];
        }

        return [
            'head' => [__('Date'), __('Details'), __('Earned'), __('Paid'), __('Balance')],
            'rows' => $rows, 'date' => true, 'moneyCols' => [2, 3, 4], 'totals' => $totals,
            'filters' => [$this->sel('worker_id', 'Worker', opts(Worker::class))],
            'note' => $r->worker_id ? null : __('Select a worker to see the statement.'),
        ];
    }

    private function r_customer_ledger(Request $r): array
    {
        $rows = [];
        $totals = [];
        if ($r->customer_id && ($c = Customer::find($r->customer_id))) {
            [$f, $t] = $this->dates($r);
            $ev = collect();
            foreach (Invoice::where('customer_id', $c->id)->get() as $i) {
                $ev->push([$i->date, __('Invoice').' '.$i->number, (float) $i->total, 0.0]);
            }
            foreach (CustomerPayment::withoutGlobalScopes()->where('customer_id', $c->id)->get() as $p) {
                $ev->push([$p->date, __('Payment').' ('.label($p->mode).')'.($p->reference ? " {$p->reference}" : ''), 0.0, (float) $p->amount]);
            }
            $bal = 0;
            $ev = $ev->sortBy(fn ($e) => $e[0]->timestamp)->values();
            $opening = $f ? $ev->filter(fn ($e) => $e[0]->lt($f))->sum(fn ($e) => $e[2] - $e[3]) : 0;
            $bal = $opening;
            foreach ($ev->filter(fn ($e) => (! $f || $e[0]->gte($f)) && (! $t || $e[0]->lte($t))) as $e) {
                $bal += $e[2] - $e[3];
                $rows[] = [fmt_date($e[0]), $e[1], $e[2] ?: '', $e[3] ?: '', round($bal, 2)];
            }
            $totals = [__('Opening') => $opening, __('Outstanding') => round($bal, 2)];
        }

        return [
            'head' => [__('Date'), __('Details'), __('Billed'), __('Received'), __('Balance')],
            'rows' => $rows, 'date' => true, 'moneyCols' => [2, 3, 4], 'totals' => $totals,
            'filters' => [$this->sel('customer_id', 'Customer', opts(Customer::class))],
            'note' => $r->customer_id ? null : __('Select a customer to see the ledger.'),
        ];
    }

    private function r_cash(Request $r): array
    {
        $cb = Finance::cashBank($r->query('date_to') ?: null);
        $rows = [];
        foreach (['cash' => __('Cash in hand'), 'bank' => __('Bank')] as $k => $l) {
            $rows[] = [$l, $cb[$k]['in'], $cb[$k]['out'], $cb[$k]['balance']];
        }

        return [
            'head' => [__('Account'), __('Total in (investments + customer receipts)'), __('Total out (expenses, wages, withdrawals)'), __('Balance')],
            'rows' => $rows, 'dateTo' => true, 'moneyCols' => [1, 2, 3],
            'totals' => [__('Total liquid funds') => $cb['cash']['balance'] + $cb['bank']['balance']],
            'filters' => [],
        ];
    }

    private function r_profit_loss(Request $r): array
    {
        [$f, $t] = $this->dates($r);
        $pl = Finance::profitLoss($f, $t);

        return [
            'head' => [__('Item'), __('Amount')], 'rows' => $pl['rows'], 'date' => true, 'moneyCols' => [1],
            'totals' => [__('Total costs') => $pl['costs'], $pl['net'] >= 0 ? __('Net profit') : __('Net loss') => $pl['net']],
            'filters' => [],
        ];
    }

    private function r_balance_sheet(Request $r): array
    {
        $b = Finance::balanceSheet();
        $rows = [
            [__('ASSETS'), ''],
            [__('Fixed assets (at cost)'), $b['fixed']], [__('Cash in hand'), $b['cash']], [__('Bank'), $b['bank']],
            [__('Receivable from customers'), $b['recv']], [__('Advances held by workers'), $b['advances']],
            [__('Total assets'), $b['assets']],
            [__('LIABILITIES'), ''],
            [__('Payable to workers'), $b['payable']], [__('Customer advances held (not yet applied to invoices)'), $b['custAdv']], [__('Payable to vendors (credit purchases)'), $b['vendors']],
            [__('NET WORTH (assets − liabilities)'), $b['net']],
            [__('Capital invested (net of withdrawals)'), $b['capital']],
            [__('Earnings retained in business'), $b['earned']],
        ];

        return ['head' => [__('Item'), __('Amount')], 'rows' => $rows, 'moneyCols' => [1], 'filters' => [],
            'note' => __('As of today. Record machine/furniture purchases both in Assets and as a Unit expense so cash is reduced.')];
    }

    private function r_assets(Request $r): array
    {
        $q = Asset::with('vendor')->when($r->type, fn ($x, $v) => $x->where('type', $v))->when($r->status, fn ($x, $v) => $x->where('status', $v))->orderBy('type');
        $rows = [];
        $sum = 0;
        foreach ($q->get() as $a) {
            $rows[] = [$a->name, label($a->type), $a->asset_no ?: '—', $a->brand ?: '—', $a->model ?: '—', fmt_date($a->purchase_date), label($a->status), (float) $a->cost];
            $sum += $a->cost;
        }

        return [
            'head' => [__('Name'), __('Type'), __('No.'), __('Brand'), __('Model'), __('Purchased'), __('Status'), __('Cost')],
            'rows' => $rows, 'moneyCols' => [7], 'totals' => [__('Total cost') => $sum],
            'filters' => [
                $this->sel('type', 'Type', ['machine' => __('Machine'), 'furniture' => __('Furniture'), 'other' => __('Other')]),
                $this->sel('status', 'Status', ['active' => __('Active'), 'repair' => __('Under repair'), 'sold' => __('Sold'), 'scrapped' => __('Scrapped')]),
            ],
        ];
    }

    private function r_vendor_payables(Request $r): array
    {
        $rows = [];
        $tb = $tp = $to = 0;
        $q = \App\Models\Vendor::query()->when($r->vendor_id, fn ($x, $v) => $x->where('id', $v))->orderBy('name');
        foreach ($q->get() as $v) {
            $billed = $v->billed();
            $paid = $v->paid();
            $owe = $v->payable();
            if ($billed == 0 && $paid == 0) {
                continue;
            }
            $open = $v->openBills();
            $oldest = $open ? collect($open)->min(fn ($b) => $b['due']->timestamp) : null;
            $late = $oldest && $oldest < now()->startOfDay()->timestamp;
            $rows[] = [$v->name, $v->phone ?: '—', $billed, $paid, $owe, $oldest ? fmt_date(\Carbon\Carbon::createFromTimestamp($oldest)) : '—',
                $owe <= 0 ? __('Settled') : ($late ? __('OVERDUE') : __('Due later'))];
            $tb += $billed; $tp += $paid; $to += $owe;
        }

        return [
            'head' => [__('Vendor'), __('Phone'), __('Bought on credit'), __('Paid'), __('We owe'), __('Oldest due date'), __('Status')],
            'rows' => $rows, 'moneyCols' => [2, 3, 4],
            'filters' => [$this->sel('vendor_id', 'Vendor', opts(\App\Models\Vendor::class))],
            'totals' => [__('Bought on credit') => $tb, __('Paid') => $tp, __('Total payable') => $to],
        ];
    }

    private function r_production(Request $r): array
    {
        $q = $this->inRange(Order::with('customer'), $r)
            ->when($r->customer_id, fn ($x, $v) => $x->where('customer_id', $v))
            ->when($r->status, fn ($x, $v) => $x->where('status', $v))
            ->orderBy('due_date')->orderBy('id');
        $rows = [];
        $tot = ['qty' => 0, 'pack' => 0, 'del' => 0, 'rej' => 0, 'late' => 0];
        foreach ($q->get() as $o) {
            $s = \App\Support\Production::summary($o);
            $st = $s['stages'];
            $late = $o->due_date && $o->due_date->lt(now()->startOfDay()) && $s['delivered'] < $o->qty && ! in_array($o->status, ['delivered', 'cancelled']);
            $rows[] = [$o->order_no, $o->customer->name, $o->collection_name ?: '—', $o->due_date ? fmt_date($o->due_date) : '—', $o->qty,
                $st['cutting'], $st['stitching'], $st['finishing'], $st['quality'], $st['packing'], $s['delivered'], $s['rejected'], $s['rework'],
                max(0, $o->qty - $s['delivered']), $s['percent'].'%', $late ? __('LATE') : label($o->status)];
            $tot['qty'] += $o->qty; $tot['pack'] += $st['packing']; $tot['del'] += $s['delivered']; $tot['rej'] += $s['rejected']; $tot['late'] += $late ? 1 : 0;
        }

        return [
            'head' => [__('Order #'), __('Client'), __('Collection'), __('Due'), __('Ordered'), __('Cut'), __('Stitched'), __('Finished'), __('QC'), __('Packed'),
                __('Delivered'), __('Rejected'), __('Rework'), __('Balance'), __('Progress'), __('Status')],
            'rows' => $rows, 'date' => true,
            'filters' => [
                $this->sel('customer_id', 'Client', opts(Customer::class)),
                $this->sel('status', 'Status', collect(Order::STATUSES)->mapWithKeys(fn ($s) => [$s => label($s)])->all()),
            ],
            'totals' => [__('Pieces ordered') => $tot['qty'], __('Packed') => $tot['pack'], __('Delivered') => $tot['del'], __('Rejected') => $tot['rej'], __('Late orders') => $tot['late']],
        ];
    }

    private function r_quality(Request $r): array
    {
        $rows = [];
        $tr = $tw = $td = 0;
        $q = \App\Models\ProductionReject::withoutGlobalScopes()->with('worker', 'order', 'adjustment')
            ->when($r->worker_id, fn ($x, $v) => $x->where('worker_id', $v))
            ->when($r->kind, fn ($x, $v) => $x->where('kind', $v));
        $q = $this->inRange($q, $r)->orderBy('date');
        foreach ($q->get() as $x) {
            $rows[] = [fmt_date($x->date), $x->worker?->name ?: __('(not assigned)'), $x->order->order_no, label($x->kind), $x->stage ? \App\Support\Production::label($x->stage) : '—',
                $x->qty, $x->reason ?: '—', $x->adjustment ? (float) $x->adjustment->amount : 0.0];
            $x->kind === 'reject' ? $tr += $x->qty : $tw += $x->qty;
            $td += $x->adjustment ? (float) $x->adjustment->amount : 0;
        }

        return [
            'head' => [__('Date'), __('Worker'), __('Order'), __('Type'), __('Stage'), __('Pieces'), __('Reason'), __('Deducted')],
            'rows' => $rows, 'date' => true, 'moneyCols' => [7],
            'filters' => [
                $this->sel('worker_id', 'Worker', opts(Worker::class)),
                $this->sel('kind', 'Type', ['reject' => __('Rejected'), 'rework' => __('Rework')]),
            ],
            'totals' => [__('Rejected pieces') => $tr, __('Rework pieces') => $tw, __('Total deducted from pay') => $td],
        ];
    }

    private function r_inventory(Request $r): array
    {
        $rows = [];
        foreach (InventoryItem::orderBy('name')->get() as $i) {
            $in = (float) $i->movements()->withoutGlobalScopes()->where('type', 'in')->sum('qty');
            $out = (float) $i->movements()->withoutGlobalScopes()->where('type', 'out')->sum('qty');
            $rows[] = [$i->name, $i->unit, $in, $out, $in - $out, (float) $i->min_stock, ($in - $out) <= (float) $i->min_stock ? __('Low') : __('OK')];
        }

        return ['head' => [__('Item'), __('Unit'), __('In'), __('Out'), __('Stock'), __('Min'), __('Status')], 'rows' => $rows, 'filters' => []];
    }

    private function sel(string $name, string $label, array $options): array
    {
        return ['name' => $name, 'label' => __($label), 'options' => $options];
    }
}
