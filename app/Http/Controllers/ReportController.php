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
        $q = $this->inRange(Order::with('customer', 'brand'), $r)
            ->when($r->customer_id, fn ($x, $v) => $x->where('customer_id', $v))
            ->when($r->brand_id, fn ($x, $v) => $x->where('brand_id', $v))
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
            $rows[] = [$o->order_no, fmt_date($o->date), $o->customer->name, $o->brand?->name ?: '—', $o->collection_name ?: '—', $o->fabric_name ?: '—', $o->qty,
                (float) $o->amount, $mat, $lab, $cost, round($o->amount - $cost, 2), $o->qty ? round($cost / $o->qty, 2) : 0];
            $t['qty'] += $o->qty; $t['billed'] += $o->amount; $t['mat'] += $mat; $t['lab'] += $lab;
        }

        return [
            'head' => [__('Order #'), __('Date'), __('Customer'), __('Brand'), __('Collection'), __('Fabric'), __('Qty'), __('Billed'), __('Material cost'), __('Labour cost'), __('Total cost'), __('Profit'), __('Cost / pc')],
            'rows' => $rows, 'date' => true,
            'filters' => [
                $this->sel('customer_id', 'Customer', opts(Customer::class)),
                $this->sel('brand_id', 'Brand', opts(\App\Models\Brand::class)),
                $this->sel('status', 'Status', collect(Order::STATUSES)->mapWithKeys(fn ($s) => [$s => label($s)])->all()),
                ['name' => 'collection_name', 'label' => __('Collection')], ['name' => 'fabric_name', 'label' => __('Fabric')],
            ],
            'totals' => [__('Pieces') => $t['qty'], __('Billed') => $t['billed'], __('Material cost') => $t['mat'], __('Labour cost') => $t['lab'],
                __('Profit') => round($t['billed'] - $t['mat'] - $t['lab'], 2)],
            'moneyCols' => [7, 8, 9, 10, 11, 12],
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
        $how = ['bank' => 0.0, 'cash' => 0.0, 'credit' => 0.0];
        foreach ($q->get() as $e) {
            $rows[] = [fmt_date($e->date), label($e->type), $e->category?->name ?: '—', $e->order?->order_no ?: '—', $e->title ?: '—',
                $e->vendor?->name ?: ($e->payee ?: '—'), $e->payment_mode === 'partial' ? __('Partly on credit') : label($e->payment_mode), (float) $e->base_amount];
            $paid = ['bank' => 0.0, 'cash' => 0.0];
            if ($e->payment_mode === 'partial') {
                $paid[$e->paid_via === 'bank' ? 'bank' : 'cash'] = $e->paidNowBase();
            } elseif (in_array($e->payment_mode, ['bank', 'cash', 'other'], true)) {
                $paid[$e->payment_mode === 'bank' ? 'bank' : 'cash'] = (float) $e->base_amount;
            }
            $how['bank'] += $paid['bank'];
            $how['cash'] += $paid['cash'];
            $how['credit'] += $e->creditAmount();
            $by[$e->type] = ($by[$e->type] ?? 0) + $e->base_amount;
            $sum += $e->base_amount;
        }
        $totals = [];
        foreach ($by as $k => $v) {
            $totals[label($k)] = $v;
        }
        $totals[__('Total')] = $sum;
        $totals[__('— paid from the bank')] = round($how['bank'], 2);
        $totals[__('— paid in cash')] = round($how['cash'], 2);
        $totals[__('— bought on credit (owed to vendors until paid)')] = round($how['credit'], 2);

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
        $te = $tp = $tb = $tpd = 0;
        $q = Worker::query()->when($r->type, fn ($x, $v) => $x->where('type', $v))->when($r->worker_id, fn ($x, $v) => $x->where('id', $v))->orderBy('name');
        foreach ($q->get() as $w) {
            $pd = $w->openingDue($f, $t);
            $e = $w->earned($f, $t);
            $p = $w->paid($f, $t);
            $bal = $w->balance();
            $rows[] = [$w->name, label($w->type), label($w->pay_cycle), $pd, $e, $p, $bal, $bal > 0 ? __('Payable') : ($bal < 0 ? __('Advance held') : __('Settled'))];
            $te += $e; $tp += $p; $tb += $bal; $tpd += $pd;
        }

        return [
            'head' => [__('Worker'), __('Type'), __('Cycle'), __('Previous due'), __('Earned (period)'), __('Paid & advances (period)'), __('Balance now'), __('Status')],
            'rows' => $rows, 'date' => true, 'moneyCols' => [3, 4, 5, 6],
            'filters' => [
                $this->sel('worker_id', 'Worker', opts(Worker::class)),
                $this->sel('type', 'Type', ['freelancer' => __('Freelancer'), 'salaried' => __('Employee')]),
            ],
            'totals' => [__('Previous due') => $tpd, __('Earned') => $te, __('Paid & advances') => $tp, __('Net payable') => $tb],
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
            $totals = [__('Opening') => $l['opening'], __('Previous due') => $l['prevDue'], __('Earned') => $l['earned'], __('Paid') => $l['payments'], __('Advances') => $l['advances'], __('Pending balance') => $l['closing']];
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

    /** Cash book: every movement of the chosen account with a running balance, opening and closing (ties to the dashboard). */
    private function r_cashbook(Request $r): array
    {
        $mode = $r->query('account') === 'cash' ? 'cash' : 'bank';
        [$f, $t] = $this->dates($r);
        $all = Finance::cashMovements($mode);
        $day = fn ($m) => $m['date']->toDateString();

        $opening = $f ? round($all->filter(fn ($m) => $day($m) < $f)->sum(fn ($m) => $m['in'] - $m['out']), 2) : 0.0;
        $rows = $f ? [[fmt_date($f), __('Opening balance'), '', '', $opening]] : [];
        $bal = $opening;
        $in = $out = 0.0;
        foreach ($all->filter(fn ($m) => (! $f || $day($m) >= $f) && (! $t || $day($m) <= $t)) as $m) {
            $bal = round($bal + $m['in'] - $m['out'], 2);
            $in += $m['in'];
            $out += $m['out'];
            $rows[] = [fmt_date($m['date']), $m['desc'], $m['in'] ?: '', $m['out'] ?: '', $bal];
        }

        return [
            'head' => [__('Date'), __('Details'), __('Money in'), __('Money out'), __('Balance')],
            'rows' => $rows, 'date' => true, 'moneyCols' => [2, 3, 4],
            'totals' => [__('Opening balance') => $opening, __('Total in') => round($in, 2), __('Total out') => round($out, 2), __('Closing balance') => $bal],
            'filters' => [$this->sel('account', 'Account', ['bank' => __('Bank'), 'cash' => __('Cash in hand')])],
            'note' => __('Showing :a. Choose the account above. Cash taken from the bank appears as money out of the bank and money in to cash in hand.', ['a' => $mode === 'cash' ? __('Cash in hand') : __('Bank')]),
        ];
    }

    /** Profit & loss month by month (whole calendar months; default: the last 12 months). */
    private function r_monthly(Request $r): array
    {
        [$f, $t] = $this->dates($r);
        $end = ($t ? \Carbon\Carbon::parse($t) : now())->endOfMonth();
        $start = ($f ? \Carbon\Carbon::parse($f) : $end->copy()->subMonthsNoOverflow(11))->startOfMonth();
        if ($start->diffInMonths($end) > 59) {
            $start = $end->copy()->subMonthsNoOverflow(59)->startOfMonth();
        }

        $rows = [];
        $tot = array_fill_keys(['revenue', 'exp', 'wages', 'costs', 'net', 'in', 'out'], 0.0);
        for ($m = $start->copy(); $m->lte($end); $m->addMonthNoOverflow()) {
            $a = $m->copy()->startOfMonth()->toDateString();
            $b = $m->copy()->endOfMonth()->toDateString();
            $pl = Finance::profitLoss($a, $b);
            $cf = Finance::cashFlow($a, $b);
            $rows[] = [$m->translatedFormat('F Y'), $pl['revenue'], $pl['expense_costs'], $pl['labour_costs'], $pl['costs'], $pl['net'], $pl['old_dues_paid'] ?: '', $cf['in'], $cf['out'], $cf['net']];
            $tot['revenue'] += $pl['revenue']; $tot['exp'] += $pl['expense_costs']; $tot['wages'] += $pl['labour_costs'];
            $tot['costs'] += $pl['costs']; $tot['net'] += $pl['net']; $tot['in'] += $cf['in']; $tot['out'] += $cf['out'];
        }

        return [
            'head' => [__('Month'), __('Revenue (invoiced)'), __('Expenses'), __('Wages paid'), __('Total costs'), __('Net profit / loss'), __('of which old dues'), __('Money in'), __('Money out'), __('Net cash flow')],
            'rows' => $rows, 'date' => true, 'moneyCols' => [1, 2, 3, 4, 5, 6, 7, 8, 9],
            'totals' => [__('Revenue (invoiced)') => $tot['revenue'], __('Total costs') => $tot['costs'], __('Net profit / loss') => $tot['net'], __('Money in') => $tot['in'], __('Money out') => $tot['out'], __('Net cash flow') => round($tot['in'] - $tot['out'], 2)],
            'filters' => [],
            'note' => __('Whole calendar months. PROFIT: revenue is invoiced; costs are expenses (when bought, incl. credit purchases) plus wages, salaries and advances when paid. CASH: money in = customer payments (incl. advances) + investments; money out = expenses paid when bought, wages, vendor payments and withdrawals; moving cash to or from the bank is not counted. Wages paid is everything paid to workers (the column "of which old dues" shows the part that went toward dues from before the app).'),
        ];
    }

    /** What customers owe: per customer, with ageing of the unpaid invoice amounts, plus advances held and overpayments. */
    private function r_receivables(Request $r): array
    {
        $today = now()->startOfDay();
        $byCustomer = Finance::invoiceBalances()->groupBy('customer_id');
        $rows = [];
        $t = array_fill_keys(['inv', 'rec', 'out', 'cur', 'd30', 'd60', 'd90', 'd90p', 'over', 'adv'], 0.0);

        foreach (Customer::query()->when($r->customer_id, fn ($x, $v) => $x->where('id', $v))->orderBy('name')->get() as $c) {
            $inv = $byCustomer[$c->id] ?? collect();
            $b = ['cur' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0];
            foreach ($inv->where('balance', '>', 0.004) as $i) {
                $late = (int) $today->diffInDays(($i['due'] ?? $i['date'])->copy()->startOfDay(), false) * -1;   // days past due
                $b[$late <= 0 ? 'cur' : ($late <= 30 ? 'd30' : ($late <= 60 ? 'd60' : ($late <= 90 ? 'd90' : 'd90p')))] += $i['balance'];
            }
            $row = ['inv' => $inv->sum('total'), 'rec' => $inv->sum('paid'), 'out' => array_sum($b),
                'over' => $inv->sum(fn ($i) => max(0, -$i['balance'])), 'adv' => $c->advance()] + $b;
            if (! $inv->count() && abs($row['adv']) < 0.004) {
                continue;
            }
            $blank = fn ($v) => abs($v) < 0.004 ? '' : round($v, 2);      // zeros as blanks
            $rows[] = [$c->name, $row['inv'], $row['rec'], $row['out'], $blank($row['cur']), $blank($row['d30']), $blank($row['d60']), $blank($row['d90']), $blank($row['d90p']), $blank($row['over']), $blank($row['adv'])];
            foreach ($t as $k => $_) {
                $t[$k] += $row[$k];
            }
        }

        return [
            'head' => [__('Customer'), __('Invoiced'), __('Received'), __('Outstanding'), __('Current'), '1–30 '.__('days late'), '31–60', '61–90', '90+',
                __('Overpaid'), __('Advance held')],
            'rows' => $rows, 'moneyCols' => range(1, 10),
            'totals' => [__('Total outstanding') => $t['out'], __('Past due') => round($t['d30'] + $t['d60'] + $t['d90'] + $t['d90p'], 2),
                __('Advances held (unused)') => $t['adv'], __('Overpaid (owed back / to apply)') => $t['over']],
            'filters' => [$this->sel('customer_id', 'Customer', opts(Customer::class))],
            'note' => __('As of today. Each invoice counts on its own, so one customer\'s overpayment never hides another customer\'s debt. Ageing runs from the due date, or the invoice date when there is none.'),
        ];
    }

    private function r_profit_loss(Request $r): array
    {
        [$f, $t] = $this->dates($r);
        $pl = Finance::profitLoss($f, $t);

        return [
            'head' => [__('Item'), __('Amount')], 'rows' => $pl['rows'], 'date' => true, 'moneyCols' => [1],
            'totals' => [__('Total costs') => $pl['costs'], $pl['net'] >= 0 ? __('Net profit') : __('Net loss') => $pl['net'],
                __('Memo: of the wages paid, toward dues from before the app') => $pl['old_dues_paid']],
            'filters' => [],
            'note' => __('Revenue is what you invoiced in the period. Costs are expenses (counted when bought, including credit purchases) plus wages, salaries and advances when you pay them; work entered but not yet paid is not a cost until paid (the memo shows how much of the wages paid went toward dues from before the app). Advances received from customers are not revenue until invoiced.'),
        ];
    }

    private function r_balance_sheet(Request $r): array
    {
        $b = Finance::balanceSheet();
        $rows = [
            [__('ASSETS'), ''],
            [__('Fixed assets (at cost)'), $b['fixed']], [__('Cash in hand'), $b['cash']], [__('Bank'), $b['bank']],
            [__('Receivable from customers'), $b['recv']], [__('Advances held by workers (paid ahead of work)'), $b['advances']],
            [__('Total assets'), $b['assets']],
            [__('LIABILITIES'), ''],
            [__('Payable to workers'), $b['payable']], [__('Customer advances held (not yet applied to invoices)'), $b['custAdv']], [__('Customer overpayments (received beyond the invoice)'), $b['overpaid']], [__('Payable to vendors (credit purchases)'), $b['vendors']],
            [__('NET WORTH (assets − liabilities)'), $b['net']],
            [__('Capital invested (net of withdrawals)'), $b['capital']],
            [__('Earnings retained in business'), $b['earned']],
            [__('HOW EARNINGS RETAINED TIES TO THE PROFIT & LOSS'), ''],
            [__('Net profit / loss since the start (Profit & Loss, all time)'), $b['recon']['pl']],
            [__('Add: machinery / furniture bought (expensed in the P&L, kept as an asset here)'), $b['recon']['fixed']],
            [__('Less: labour earned but not yet paid (a cost only when paid)'), -$b['recon']['labour_unpaid']],
            [__('Less: dues owed to workers from before the app'), -$b['recon']['previous_due']],
            [__('Earnings retained in business (matches the figure above)'), round($b['recon']['pl'] + $b['recon']['fixed'] - $b['recon']['labour_unpaid'] - $b['recon']['previous_due'], 2)],
            [__('Unexplained difference (should be 0.00)'), $b['recon']['difference']],
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
        $q = $this->inRange(Order::with('customer', 'brand'), $r)
            ->when($r->customer_id, fn ($x, $v) => $x->where('customer_id', $v))
            ->when($r->status, fn ($x, $v) => $x->where('status', $v))
            ->orderBy('due_date')->orderBy('id');
        $rows = [];
        $tot = ['qty' => 0, 'pack' => 0, 'del' => 0, 'rej' => 0, 'late' => 0];
        foreach ($q->get() as $o) {
            $s = \App\Support\Production::summary($o);
            $st = $s['stages'];
            $late = $o->due_date && $o->due_date->lt(now()->startOfDay()) && $s['delivered'] < $o->qty && ! in_array($o->status, ['delivered', 'cancelled']);
            $rows[] = [$o->order_no, $o->customer->name, $o->brand_collection ?: '—', $o->due_date ? fmt_date($o->due_date) : '—', $o->qty,
                $st['cutting'], $st['stitching'], $st['finishing'], $st['quality'], $st['packing'], $s['delivered'], $s['rejected'], $s['rework'],
                max(0, $o->qty - $s['delivered']), $s['percent'].'%', $late ? __('LATE') : label($o->status)];
            $tot['qty'] += $o->qty; $tot['pack'] += $st['packing']; $tot['del'] += $s['delivered']; $tot['rej'] += $s['rejected']; $tot['late'] += $late ? 1 : 0;
        }

        return [
            'head' => [__('Order #'), __('Customer'), __('Brand · Collection'), __('Due'), __('Ordered'), __('Cut'), __('Stitched'), __('Finished'), __('QC'), __('Packed'),
                __('Delivered'), __('Rejected'), __('Rework'), __('Balance'), __('Progress'), __('Status')],
            'rows' => $rows, 'date' => true,
            'filters' => [
                $this->sel('customer_id', 'Customer', opts(Customer::class)),
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
