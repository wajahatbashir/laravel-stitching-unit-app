<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Investment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\SalaryEntry;
use App\Models\Worker;
use App\Models\VendorPayment;
use App\Models\WorkerAdjustment;
use App\Models\WorkerPayment;
use App\Models\WorkEntry;

/** Company-wide money maths (always ignoring per-user row scopes). */
class Finance
{
    /** Cash-in-hand and bank balances, optionally as of a date. */
    public static function cashBank(?string $asOf = null): array
    {
        $d = fn ($q, $col = 'date') => $asOf ? $q->whereDate($col, '<=', $asOf) : $q;
        $out = [];
        foreach (['cash', 'bank'] as $mode) {
            // cash taken out of the bank lands in cash; cash deposited lands in the bank
            $moved = $mode === 'cash' ? 'bank_to_cash' : 'cash_to_bank';
            $left = $mode === 'cash' ? 'cash_to_bank' : 'bank_to_cash';
            $in = (float) $d(Investment::withoutGlobalScopes()->where('type', 'investment')->where('mode', $mode))->sum('amount')
                + (float) $d(CustomerPayment::withoutGlobalScopes()->where('mode', $mode))->sum('amount')
                + (float) $d(\App\Models\CashTransfer::withoutGlobalScopes()->where('direction', $moved))->sum('amount');
            $spent = (float) $d(Investment::withoutGlobalScopes()->where('type', 'withdrawal')->where('mode', $mode))->sum('amount')
                + (float) $d(\App\Models\CashTransfer::withoutGlobalScopes()->where('direction', $left))->sum('amount')
                + (float) $d(WorkerPayment::withoutGlobalScopes()->where('mode', $mode))->sum('amount')
                + (float) $d(VendorPayment::withoutGlobalScopes()->where('mode', $mode))->sum('amount')
                + (float) $d(Expense::withoutGlobalScopes()->whereIn('payment_mode', $mode === 'cash' ? ['cash', 'other'] : ['bank']))->sum('base_amount')
                // the part paid at purchase of a "partly on credit" expense
                + (float) $d(Expense::withoutGlobalScopes()->where('payment_mode', 'partial')->whereIn('paid_via', $mode === 'cash' ? ['cash', 'other'] : ['bank']))->sum(\DB::raw('paid_now * exchange_rate'));
            $out[$mode] = ['in' => $in, 'out' => $spent, 'balance' => round($in - $spent, 2)];
        }

        return $out;
    }

    /**
     * Every invoice with what has been paid against it (direct payments + advances applied) and what is left.
     * A negative balance means the customer paid more than the invoice.
     *
     * @return \Illuminate\Support\Collection<int,array{id:int,number:string,customer_id:int,date:\Carbon\Carbon,due:?\Carbon\Carbon,total:float,paid:float,balance:float}> keyed by invoice id
     */
    public static function invoiceBalances(): \Illuminate\Support\Collection
    {
        $direct = CustomerPayment::withoutGlobalScopes()->whereNotNull('invoice_id')->selectRaw('invoice_id, SUM(amount) s')->groupBy('invoice_id')->pluck('s', 'invoice_id');
        $applied = \App\Models\PaymentAllocation::selectRaw('invoice_id, SUM(amount) s')->groupBy('invoice_id')->pluck('s', 'invoice_id');

        return Invoice::get(['id', 'number', 'customer_id', 'date', 'due_date', 'total'])->mapWithKeys(function ($i) use ($direct, $applied) {
            $paid = round((float) ($direct[$i->id] ?? 0) + (float) ($applied[$i->id] ?? 0), 2);

            return [$i->id => ['id' => $i->id, 'number' => $i->number, 'customer_id' => $i->customer_id, 'date' => $i->date, 'due' => $i->due_date,
                'total' => (float) $i->total, 'paid' => $paid, 'balance' => round((float) $i->total - $paid, 2)]];
        });
    }

    /** Still owed on invoices. Each invoice counts on its own: one customer's overpayment never hides another customer's debt. */
    public static function receivables(): float
    {
        return round(self::invoiceBalances()->sum(fn ($i) => max(0, $i['balance'])), 2);
    }

    /** Money received beyond what the invoices asked for (a liability: to be refunded or applied). */
    public static function overpaid(): float
    {
        return round(self::invoiceBalances()->sum(fn ($i) => max(0, -$i['balance'])), 2);
    }

    /** Unpaid invoice amounts that are past their due date (invoice date when there is no due date). */
    public static function overdueReceivables(): float
    {
        $today = now()->startOfDay();

        return round(self::invoiceBalances()->filter(fn ($i) => $i['balance'] > 0.004 && ($i['due'] ?? $i['date'])->copy()->startOfDay()->lt($today))->sum('balance'), 2);
    }
    /** What we owe vendors for credit purchases, net of payments made (a liability). */
    public static function vendorPayables(): float
    {
        $bills = (float) Expense::withoutGlobalScopes()->whereIn('payment_mode', Expense::CREDIT_MODES)
            ->sum(\DB::raw("CASE WHEN payment_mode = 'partial' THEN base_amount - COALESCE(paid_now, 0) * exchange_rate ELSE base_amount END"));

        return round($bills - (float) \App\Models\VendorPayment::sum('amount'), 2);
    }

    /** Advance money held for customers (liability until applied to invoices). */
    public static function customerAdvances(): float
    {
        return round((float) CustomerPayment::withoutGlobalScopes()->whereNull('invoice_id')->sum('amount') - (float) \App\Models\PaymentAllocation::sum('amount'), 2);
    }

    /** [payable to workers, advances held by workers] */
    public static function workerBalances(): array
    {
        $pay = 0.0;
        $adv = 0.0;
        foreach (Worker::all() as $w) {
            $b = $w->balance();
            $b > 0 ? $pay += $b : $adv += -$b;
        }

        return [round($pay, 2), round($adv, 2)];
    }

    public static function profitLoss(?string $from, ?string $to): array
    {
        $range = fn ($q, $col = 'date') => $q->when($from, fn ($x) => $x->whereDate($col, '>=', $from))->when($to, fn ($x) => $x->whereDate($col, '<=', $to));

        $revenue = (float) $range(Invoice::query())->sum('total');
        $exp = $range(Expense::withoutGlobalScopes())->selectRaw('type, SUM(base_amount) s')->groupBy('type')->pluck('s', 'type');
        // Labour is a cost on the day it is PAID (wages, salaries and advances paid to workers), not when the work is entered.
        // Money paid toward dues owed from before the app (a worker's "previous due") is paid like any other wages, so it IS counted here;
        // $oldDues only reports how much of it that was. Work / salary entries still drive what each worker is owed and the per-order costing.
        $oldDues = self::previousDuePaid($from, $to);
        $labour = (float) $range(WorkerPayment::withoutGlobalScopes())->sum('amount');

        $rows = [[__('Revenue (invoiced)'), $revenue]];
        $costs = 0.0;
        foreach (['order' => 'Order expenses (materials)', 'unit' => 'Unit expenses', 'labour' => 'Labour expenses (food etc.)', 'other' => 'Other expenses (rent, bills)'] as $k => $l) {
            $v = (float) ($exp[$k] ?? 0);
            $costs += $v;
            $rows[] = [__($l), -$v];
        }
        $rows[] = [__('Wages & salaries paid'), -$labour];
        $costs += $labour;

        return ['rows' => $rows, 'revenue' => $revenue, 'costs' => $costs, 'net' => round($revenue - $costs, 2),
            'expense_costs' => round($costs - $labour, 2), 'labour_costs' => round($labour, 2), 'old_dues_paid' => $oldDues];
    }

    /**
     * Labour EARNED in a period (piece-rate work + salaries, minus deductions, plus bonuses) — what is owed to workers for the work done.
     * It is not a cost until paid (see profitLoss); what is still unpaid is workerBalances()[0].
     */
    public static function labourEarned(?string $from, ?string $to): float
    {
        $range = fn ($q, $col = 'date') => $q->when($from, fn ($x) => $x->whereDate($col, '>=', $from))->when($to, fn ($x) => $x->whereDate($col, '<=', $to));

        return round((float) $range(WorkEntry::withoutGlobalScopes())->sum('amount')
            + (float) $range(WorkerAdjustment::withoutGlobalScopes())->sum(\DB::raw("CASE WHEN type = 'deduction' THEN -amount ELSE amount END"))
            + (float) $range(SalaryEntry::withoutGlobalScopes(), 'period_month')->selectRaw('SUM(amount + bonus - deduction) s')->value('s'), 2);
    }

    /** Revenue (invoiced) vs total costs for each of the last $months months, oldest first. */
    public static function monthlySeries(int $months = 6): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonthsNoOverflow($i);
            $from = $start->toDateString();
            $to = $start->copy()->endOfMonth()->toDateString();
            $pl = self::profitLoss($from, $to);
            $out[] = ['label' => $start->translatedFormat('M'), 'full' => $start->translatedFormat('F Y'),
                'revenue' => round($pl['revenue'], 2), 'costs' => round($pl['costs'], 2), 'profit' => $pl['net']];
        }

        return $out;
    }

    /**
     * Revenue vs costs per bucket ('day' | 'week' | 'month') between two dates; first/last buckets are clipped to the range.
     */
    public static function series(\Carbon\Carbon $from, \Carbon\Carbon $to, string $bucket): array
    {
        $out = [];
        $cur = $from->copy()->startOfDay();
        $multiYear = $from->year !== $to->year;
        while ($cur->lte($to)) {
            $end = match ($bucket) {
                'day' => $cur->copy()->endOfDay(),
                'week' => $cur->copy()->endOfWeek(),
                default => $cur->copy()->endOfMonth(),
            };
            if ($end->gt($to)) {
                $end = $to->copy()->endOfDay();
            }
            $pl = self::profitLoss($cur->toDateString(), $end->toDateString());
            [$label, $full] = match ($bucket) {
                'day' => [$cur->translatedFormat('d M'), $cur->translatedFormat('l, d F Y')],
                'week' => [$cur->translatedFormat('d M'), $cur->translatedFormat('d M').' – '.$end->translatedFormat('d M Y')],
                default => [$cur->translatedFormat($multiYear ? "M 'y" : 'M'), $cur->translatedFormat('F Y')],
            };
            $out[] = ['label' => $label, 'full' => $full, 'revenue' => round($pl['revenue'], 2), 'costs' => round($pl['costs'], 2), 'profit' => $pl['net']];
            $cur = $end->copy()->addDay()->startOfDay();
        }

        return $out;
    }

    /** Headline numbers for a period. */
    public static function periodStats(\Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $pl = self::profitLoss($f, $t);
        $orders = Order::whereBetween('date', [$f, $t]);

        return [
            'revenue' => $pl['revenue'],
            'costs' => $pl['costs'],
            'expense_costs' => $pl['expense_costs'],
            'labour_costs' => $pl['labour_costs'],
            'labour_earned' => self::labourEarned($f, $t),
            'prev_due_paid' => $pl['old_dues_paid'],
            'profit' => $pl['net'],
            'received' => (float) CustomerPayment::withoutGlobalScopes()->whereBetween('date', [$f, $t])->sum('amount'),
            'wages' => $pl['labour_costs'],                     // everything paid to workers (wages, salaries, advances, old dues)
            'orders' => (clone $orders)->count(),
            'pieces' => (int) (clone $orders)->sum('qty'),
        ];
    }

    /** % change vs the previous period; null when there is nothing to compare with. */
    public static function delta(float $now, float $prev): ?int
    {
        return abs($prev) < 0.005 ? null : (int) round(($now - $prev) / abs($prev) * 100);
    }

    /** Where the money went in a period: expense types + wages & salaries paid, largest first (zeros dropped). */
    public static function costBreakdown(?string $from, ?string $to): array
    {
        $rows = collect(self::profitLoss($from, $to)['rows'])->slice(1) // first row is revenue
            ->map(fn ($r) => [trim(preg_replace('/\s*\(.*\)/', '', $r[0])), -$r[1]])->filter(fn ($r) => $r[1] > 0)->sortByDesc(1)->values();

        return $rows->all();
    }

    /**
     * Every movement of money in or out of one account ('cash' or 'bank'), oldest first — the cash book.
     * It uses exactly the sources of cashBank(), so its closing balance always equals the balance shown on the dashboard.
     *
     * @return \Illuminate\Support\Collection<int,array{date:\Carbon\Carbon,desc:string,in:float,out:float}>
     */
    public static function cashMovements(string $mode): \Illuminate\Support\Collection
    {
        $isCash = $mode === 'cash';
        $modes = $isCash ? ['cash', 'other'] : ['bank'];
        $m = collect();
        $add = fn ($date, string $desc, float $in, float $out, int $seq) => $m->push(['date' => \Carbon\Carbon::parse($date), 'desc' => $desc, 'in' => $in, 'out' => $out, 'seq' => $seq]);

        foreach (Investment::withoutGlobalScopes()->with('investor')->where('mode', $mode)->get() as $x) {
            $x->type === 'investment'
                ? $add($x->date, __('Investment').' — '.($x->investor?->name ?? ''), (float) $x->amount, 0.0, 1)
                : $add($x->date, __('Withdrawal / return').' — '.($x->investor?->name ?? ''), 0.0, (float) $x->amount, 9);
        }
        foreach (CustomerPayment::withoutGlobalScopes()->with('customer', 'invoice')->where('mode', $mode)->get() as $x) {
            $add($x->date, __('Customer payment').' — '.($x->customer?->name ?? '').' ('.($x->invoice ? $x->invoice->number : __('advance')).')', (float) $x->amount, 0.0, 2);
        }
        foreach (\App\Models\CashTransfer::withoutGlobalScopes()->get() as $x) {
            $intoThis = ($x->direction === 'bank_to_cash') === $isCash;
            $intoThis
                ? $add($x->date, $isCash ? __('Cash taken out of the bank') : __('Cash deposited into the bank'), (float) $x->amount, 0.0, 3)
                : $add($x->date, $isCash ? __('Cash deposited into the bank') : __('Cash taken out of the bank'), 0.0, (float) $x->amount, 8);
        }
        foreach (Expense::withoutGlobalScopes()->whereIn('payment_mode', $modes)->get() as $x) {
            $add($x->date, __('Expense').' — '.($x->title ?: label($x->type)).' ('.label($x->type).')', 0.0, (float) $x->base_amount, 5);
        }
        foreach (Expense::withoutGlobalScopes()->where('payment_mode', 'partial')->whereIn('paid_via', $modes)->get() as $x) {
            $add($x->date, __('Expense (part paid now)').' — '.($x->title ?: label($x->type)), 0.0, $x->paidNowBase(), 5);
        }
        foreach (WorkerPayment::withoutGlobalScopes()->with('worker')->where('mode', $mode)->get() as $x) {
            $add($x->date, ($x->type === 'advance' ? __('Advance to worker') : __('Wages paid')).' — '.($x->worker?->name ?? ''), 0.0, (float) $x->amount, 6);
        }
        foreach (VendorPayment::withoutGlobalScopes()->with('vendor')->where('mode', $mode)->get() as $x) {
            $add($x->date, __('Vendor payment').' — '.($x->vendor?->name ?? ''), 0.0, (float) $x->amount, 7);
        }

        return $m->sortBy([['date', 'asc'], ['seq', 'asc']])->values();
    }

    /**
     * Cash flow of a period: money that really came in (customer payments incl. advances, investments) and went out
     * (expenses paid when bought, wages / salaries / advances, vendor payments, withdrawals). Moves between cash and bank are not counted.
     * Over all time, in − out equals Cash in hand + Bank.
     *
     * @return array{in:float,out:float,net:float}
     */
    public static function cashFlow(?string $from, ?string $to): array
    {
        $range = fn ($q) => $q->when($from, fn ($x) => $x->whereDate('date', '>=', $from))->when($to, fn ($x) => $x->whereDate('date', '<=', $to));

        $in = (float) $range(Investment::withoutGlobalScopes()->where('type', 'investment'))->sum('amount')
            + (float) $range(CustomerPayment::withoutGlobalScopes())->sum('amount');
        $out = (float) $range(Investment::withoutGlobalScopes()->where('type', 'withdrawal'))->sum('amount')
            + (float) $range(Expense::withoutGlobalScopes()->whereIn('payment_mode', ['cash', 'bank', 'other']))->sum('base_amount')
            + (float) $range(Expense::withoutGlobalScopes()->where('payment_mode', 'partial'))->sum(\DB::raw('paid_now * exchange_rate'))
            + (float) $range(WorkerPayment::withoutGlobalScopes())->sum('amount')
            + (float) $range(VendorPayment::withoutGlobalScopes())->sum('amount');

        return ['in' => round($in, 2), 'out' => round($out, 2), 'net' => round($in - $out, 2)];
    }

    /**
     * How much of the money paid to workers in a period went to settle dues owed to them from BEFORE the app (the "previous due" on
     * each worker). Payments clear a worker's previous due first, then newer earnings. Information only: it is part of the wages paid and of the costs.
     */
    public static function previousDuePaid(?string $from, ?string $to): float
    {
        $total = 0.0;
        foreach (Worker::where('opening_due', '>', 0)->get() as $w) {
            $due = (float) $w->opening_due;
            $before = $from ? (float) $w->paid(null, \Carbon\Carbon::parse($from)->subDay()->toDateString()) : 0.0;
            $upTo = (float) $w->paid(null, $to);
            $total += max(0.0, min($due, $upTo) - min($due, $before));
        }

        return round($total, 2);
    }

    public static function balanceSheet(): array
    {
        $cb = self::cashBank();
        $fixed = (float) Asset::whereNotIn('status', ['sold', 'scrapped'])->sum('cost');
        $recv = self::receivables();
        [$payable, $advances] = self::workerBalances();
        $capital = (float) Investment::withoutGlobalScopes()->where('type', 'investment')->sum('amount')
            - (float) Investment::withoutGlobalScopes()->where('type', 'withdrawal')->sum('amount');

        $custAdv = self::customerAdvances();
        $overpaid = self::overpaid();
        $assets = $fixed + $cb['cash']['balance'] + $cb['bank']['balance'] + $recv + $advances;
        $vendors = self::vendorPayables();
        $net = round($assets - $payable - $custAdv - $overpaid - $vendors, 2);
        $earned = round($net - $capital, 2);

        // Why "earnings retained" is not simply the all-time profit & loss: the P&L expenses machinery that the balance sheet keeps as an
        // asset, counts labour only when paid (the balance sheet owes what is earned but unpaid), and ignores dues owed from before the app.
        $pl = self::profitLoss(null, null)['net'];
        $labourUnpaid = round(self::labourEarned(null, null) - (float) WorkerPayment::withoutGlobalScopes()->sum('amount'), 2);
        $previousDue = round((float) Worker::all()->sum(fn ($w) => $w->openingDue()), 2);
        $recon = ['pl' => $pl, 'fixed' => round($fixed, 2), 'labour_unpaid' => $labourUnpaid, 'previous_due' => $previousDue,
            'difference' => round($earned - ($pl + $fixed - $labourUnpaid - $previousDue), 2)];

        return compact('fixed', 'recv', 'payable', 'advances', 'custAdv', 'overpaid', 'vendors', 'capital', 'assets', 'net', 'earned', 'recon') + [
            'cash' => $cb['cash']['balance'], 'bank' => $cb['bank']['balance'],
        ];
    }
}
