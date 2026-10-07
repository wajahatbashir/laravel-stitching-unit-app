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
            $in = (float) $d(Investment::withoutGlobalScopes()->where('type', 'investment')->where('mode', $mode))->sum('amount')
                + (float) $d(CustomerPayment::withoutGlobalScopes()->where('mode', $mode))->sum('amount');
            $spent = (float) $d(Investment::withoutGlobalScopes()->where('type', 'withdrawal')->where('mode', $mode))->sum('amount')
                + (float) $d(WorkerPayment::withoutGlobalScopes()->where('mode', $mode))->sum('amount')
                + (float) $d(VendorPayment::withoutGlobalScopes()->where('mode', $mode))->sum('amount')
                + (float) $d(Expense::withoutGlobalScopes()->whereIn('payment_mode', $mode === 'cash' ? ['cash', 'other'] : ['bank']))->sum('base_amount');
            $out[$mode] = ['in' => $in, 'out' => $spent, 'balance' => round($in - $spent, 2)];
        }

        return $out;
    }

    /** Still owed on invoices (after direct payments and applied advances). */
    public static function receivables(): float
    {
        $direct = (float) CustomerPayment::withoutGlobalScopes()->whereNotNull('invoice_id')->sum('amount');

        return round((float) Invoice::sum('total') - $direct - (float) \App\Models\PaymentAllocation::sum('amount'), 2);
    }

    /** Advance money held for customers (liability until applied to invoices). */
    /** What we owe vendors for credit purchases, net of payments made (a liability). */
    public static function vendorPayables(): float
    {
        $bills = (float) Expense::withoutGlobalScopes()->where('payment_mode', 'credit')->sum('base_amount');

        return round($bills - (float) \App\Models\VendorPayment::sum('amount'), 2);
    }

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
        $piece = (float) $range(WorkEntry::withoutGlobalScopes())->sum('amount')
            + (float) $range(WorkerAdjustment::withoutGlobalScopes())->sum(\DB::raw("CASE WHEN type = 'deduction' THEN -amount ELSE amount END")); // deductions lower, bonuses raise labour cost
        $sal = (float) $range(SalaryEntry::withoutGlobalScopes(), 'period_month')->selectRaw('SUM(amount + bonus - deduction) s')->value('s');

        $rows = [[__('Revenue (invoiced)'), $revenue]];
        $costs = 0.0;
        foreach (['order' => 'Order expenses (materials)', 'unit' => 'Unit expenses', 'labour' => 'Labour expenses (food etc.)', 'other' => 'Other expenses (rent, bills)'] as $k => $l) {
            $v = (float) ($exp[$k] ?? 0);
            $costs += $v;
            $rows[] = [__($l), -$v];
        }
        $rows[] = [__('Piece-rate labour'), -$piece];
        $rows[] = [__('Staff salaries'), -$sal];
        $costs += $piece + $sal;

        return ['rows' => $rows, 'revenue' => $revenue, 'costs' => $costs, 'net' => round($revenue - $costs, 2)];
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
            'profit' => $pl['net'],
            'received' => (float) CustomerPayment::withoutGlobalScopes()->whereBetween('date', [$f, $t])->sum('amount'),
            'wages' => (float) WorkerPayment::withoutGlobalScopes()->whereBetween('date', [$f, $t])->sum('amount'),
            'orders' => (clone $orders)->count(),
            'pieces' => (int) (clone $orders)->sum('qty'),
        ];
    }

    /** % change vs the previous period; null when there is nothing to compare with. */
    public static function delta(float $now, float $prev): ?int
    {
        return abs($prev) < 0.005 ? null : (int) round(($now - $prev) / abs($prev) * 100);
    }

    /** Where the money went in a period: expense types + piece-rate wages + salaries, largest first (zeros dropped). */
    public static function costBreakdown(?string $from, ?string $to): array
    {
        $rows = collect(self::profitLoss($from, $to)['rows'])->slice(1) // first row is revenue
            ->map(fn ($r) => [trim(preg_replace('/\s*\(.*\)/', '', $r[0])), -$r[1]])->filter(fn ($r) => $r[1] > 0)->sortByDesc(1)->values();

        return $rows->all();
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
        $assets = $fixed + $cb['cash']['balance'] + $cb['bank']['balance'] + $recv + $advances;
        $vendors = self::vendorPayables();
        $net = round($assets - $payable - $custAdv - $vendors, 2);

        return compact('fixed', 'recv', 'payable', 'advances', 'custAdv', 'vendors', 'capital', 'assets', 'net') + [
            'cash' => $cb['cash']['balance'], 'bank' => $cb['bank']['balance'], 'earned' => round($net - $capital, 2),
        ];
    }
}
