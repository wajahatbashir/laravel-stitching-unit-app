<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Models\ProductionReject;

/** Production stages and per-order progress numbers. */
class Production
{
    /** key => [label, icon] — the pieces of an order move through these in order; "delivered" comes from deliveries. */
    public const STAGES = [
        'cutting' => 'Cutting',
        'stitching' => 'Stitching',
        'finishing' => 'Finishing',
        'quality' => 'Quality check',
        'packing' => 'Packing',
    ];

    /** Pieces may exceed the order quantity by this share (extra pieces, safety stock) before we refuse the entry. */
    public const TOLERANCE = 0.10;

    public static function keys(): array
    {
        return array_keys(self::STAGES);
    }

    public static function label(string $stage): string
    {
        return __(self::STAGES[$stage] ?? ucfirst($stage));
    }

    public static function options(): array
    {
        return collect(self::STAGES)->mapWithKeys(fn ($l, $k) => [$k => __($l)])->all();
    }

    public static function cap(Order $o): int
    {
        return (int) floor($o->qty * (1 + self::TOLERANCE));
    }

    /**
     * Everything the board / order page needs: pieces done per stage, rejects, rework, delivered, the furthest stage reached.
     *
     * @return array{qty:int, stages:array<string,int>, rejected:int, rework:int, delivered:int, current:?string, percent:int}
     */
    public static function summary(Order $o): array
    {
        $by = ProductionLog::where('order_id', $o->id)->selectRaw('stage, SUM(qty) s')->groupBy('stage')->pluck('s', 'stage');
        $stages = [];
        $current = null;
        foreach (self::keys() as $k) {
            $stages[$k] = (int) ($by[$k] ?? 0);
            if ($stages[$k] > 0) {
                $current = $k;
            }
        }
        $rej = ProductionReject::where('order_id', $o->id)->selectRaw('kind, SUM(qty) s')->groupBy('kind')->pluck('s', 'kind');
        $delivered = (int) Delivery::where('order_id', $o->id)->sum('qty');
        $last = $stages['packing'] ?: ($stages['quality'] ?: ($stages['finishing'] ?: ($stages['stitching'] ?: $stages['cutting'])));

        return [
            'qty' => (int) $o->qty,
            'stages' => $stages,
            'rejected' => (int) ($rej['reject'] ?? 0),
            'rework' => (int) ($rej['rework'] ?? 0),
            'delivered' => $delivered,
            'current' => $current,
            'percent' => $o->qty > 0 ? (int) min(100, round(max($delivered, $last) / $o->qty * 100)) : 0,
        ];
    }
}
