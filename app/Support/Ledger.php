<?php

namespace App\Support;

use App\Exports\TableExport;
use App\Models\Worker;
use Illuminate\Http\Request;

/**
 * Worker ledger: earned (piece-rate + salary) minus paid (payments + advances).
 * Anything unpaid in a period is simply part of the running balance, so it carries
 * forward to the next payment automatically; an advance makes the balance negative
 * and is adjusted against future earnings.
 */
class Ledger
{
    public static function build(Worker $w, ?string $from, ?string $to): array
    {
        $opening = $from ? round($w->earned(null, self::dayBefore($from)) - $w->paid(null, self::dayBefore($from)), 2) : 0.0;

        $lines = collect();
        $we = $w->workEntries()->with('garmentType', 'order');
        $se = $w->salaryEntries();
        $pe = $w->payments();
        if ($from) { $we->whereDate('date', '>=', $from); $se->whereDate('period_month', '>=', $from); $pe->whereDate('date', '>=', $from); }
        if ($to) { $we->whereDate('date', '<=', $to); $se->whereDate('period_month', '<=', $to); $pe->whereDate('date', '<=', $to); }

        foreach ($we->get() as $e) {
            $lines->push(['date' => $e->date, 'kind' => 'work', 'desc' => "{$e->garmentType->name} × {$e->qty} @ ".number_format($e->rate, 2)
                .($e->order ? " ({$e->order->order_no})" : ''), 'earned' => (float) $e->amount, 'paid' => 0.0, 'o' => 1]);
        }
        foreach ($se->get() as $e) {
            $lines->push(['date' => $e->period_month, 'kind' => 'salary', 'desc' => __('Salary').' '.$e->period_month->format('M Y'),
                'earned' => $e->net(), 'paid' => 0.0, 'o' => 0]);
        }
        $ae = $w->adjustments();
        if ($from) { $ae->whereDate('date', '>=', $from); }
        if ($to) { $ae->whereDate('date', '<=', $to); }
        foreach ($ae->get() as $a) {
            $lines->push(['date' => $a->date, 'kind' => $a->type, 'desc' => ($a->type === 'deduction' ? __('Deduction') : __('Bonus')).($a->reason ? ': '.$a->reason : ''),
                'earned' => $a->signed(), 'paid' => 0.0, 'o' => 1]);
        }
        foreach ($pe->get() as $p) {
            $lines->push(['date' => $p->date, 'kind' => $p->type, 'desc' => label($p->type).' ('.label($p->mode).')'.($p->notes ? " — {$p->notes}" : ''),
                'earned' => 0.0, 'paid' => (float) $p->amount, 'o' => 2]);
        }

        $bal = $opening;
        $lines = $lines->sortBy([['date', 'asc'], ['o', 'asc']])->values()->map(function ($l) use (&$bal) {
            $bal = round($bal + $l['earned'] - $l['paid'], 2);
            $l['balance'] = $bal;

            return $l;
        });

        $earned = (float) $lines->sum('earned');
        $paid = (float) $lines->sum('paid');
        $closing = round($opening + $earned - $paid, 2);
        $due = round($opening + $earned, 2);
        $status = $closing <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'pending');
        if ($due <= 0 && $paid == 0) {
            $status = 'pending';
        }

        return compact('opening', 'earned', 'paid', 'closing', 'status', 'lines');
    }

    private static function dayBefore(string $d): string
    {
        return \Carbon\Carbon::parse($d)->subDay()->toDateString();
    }

    public static function view(Request $r, Worker $w, bool $own = false)
    {
        $from = $r->query('date_from');
        $to = $r->query('date_to');
        $l = self::build($w, $from, $to);

        if ($fmt = $r->query('export')) {
            $head = [__('Date'), __('Details'), __('Earned'), __('Paid'), __('Balance')];
            $rows = $l['lines']->map(fn ($x) => [fmt_date($x['date']), $x['desc'], $x['earned'] ?: '', $x['paid'] ?: '', $x['balance']])->all();
            $totals = [__('Opening') => $l['opening'], __('Earned') => $l['earned'], __('Paid') => $l['paid'], __('Closing balance') => $l['closing']];

            return TableExport::respond($w->name.' ledger', $head, $rows, $fmt, $totals);
        }

        return view('workers.show', ['w' => $w, 'own' => $own, 'from' => $from, 'to' => $to] + $l);
    }
}
