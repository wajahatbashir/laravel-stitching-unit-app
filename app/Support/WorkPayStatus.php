<?php

namespace App\Support;

use App\Models\SalaryEntry;
use App\Models\Worker;
use App\Models\WorkerAdjustment;
use App\Models\WorkerPayment;
use App\Models\WorkEntry;

/**
 * Is a work entry paid? Payments are not attached to entries — a worker's payments (and advances) simply clear what
 * they are owed oldest-first: previous due, then work / salary / deductions in date order. So an entry is Paid when the
 * payments reach past it, Partly paid when they stop inside it, Unpaid when they have not reached it. Display only.
 */
class WorkPayStatus
{
    /** @return array<int,array{amount:float,paid:float,left:float,status:string}> keyed by work-entry id */
    public static function map(): array
    {
        $pool = WorkerPayment::withoutGlobalScopes()->selectRaw('worker_id, SUM(amount) s')->groupBy('worker_id')->pluck('s', 'worker_id')->map(fn ($v) => (float) $v)->all();
        $work = WorkEntry::withoutGlobalScopes()->get(['id', 'worker_id', 'date', 'amount'])->groupBy('worker_id');
        if ($work->isEmpty()) {
            return [];
        }
        $salary = SalaryEntry::withoutGlobalScopes()->whereIn('worker_id', $work->keys())->get()->groupBy('worker_id');
        $adj = WorkerAdjustment::withoutGlobalScopes()->whereIn('worker_id', $work->keys())->get()->groupBy('worker_id');

        $out = [];
        foreach (Worker::whereIn('id', $work->keys())->get() as $w) {
            $items = collect();
            foreach ($work[$w->id] ?? [] as $e) {
                $items->push(['d' => $e->date->toDateString(), 'k' => 1, 'id' => $e->id, 'amt' => (float) $e->amount, 'work' => true]);
            }
            foreach ($salary[$w->id] ?? [] as $s) {
                $items->push(['d' => $s->period_month->toDateString(), 'k' => 0, 'id' => $s->id, 'amt' => $s->net(), 'work' => false]);
            }
            foreach ($adj[$w->id] ?? [] as $a) {
                $items->push(['d' => $a->date->toDateString(), 'k' => 2, 'id' => $a->id, 'amt' => $a->signed(), 'work' => false]);
            }

            $cum = (float) $w->openingDue();                        // what was owed before any of these items
            $paid = $pool[$w->id] ?? 0.0;
            foreach ($items->sortBy([['d', 'asc'], ['k', 'asc'], ['id', 'asc']]) as $i) {
                if ($i['work']) {
                    $cover = $i['amt'] > 0 ? max(0.0, min($i['amt'], $paid - $cum)) : 0.0;
                    $left = round($i['amt'] - $cover, 2);
                    $out[$i['id']] = ['amount' => $i['amt'], 'paid' => round($cover, 2), 'left' => $left,
                        'status' => $left <= 0.004 ? 'paid' : ($cover > 0.004 ? 'partial' : 'unpaid')];
                }
                $cum += $i['amt'];
            }
        }

        return $out;
    }

    public static function label(string $status): string
    {
        return ['paid' => __('Paid'), 'partial' => __('Partly paid'), 'unpaid' => __('Unpaid')][$status] ?? $status;
    }
}
