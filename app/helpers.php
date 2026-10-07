<?php

use App\Models\Currency;
use App\Models\Setting;
use Carbon\Carbon;

if (! function_exists('money')) {
    function money($v, bool $symbol = true): string
    {
        static $sym = null;
        $sym ??= Currency::base()?->symbol ?? 'Rs';
        $n = number_format((float) $v, 2);
        return $symbol ? "$sym $n" : $n;
    }
}

if (! function_exists('fmt_date')) {
    function fmt_date($d): string
    {
        return $d ? Carbon::parse($d)->format('d M Y') : '—';
    }
}

if (! function_exists('biz')) {
    function biz(string $key, $default = null)
    {
        return Setting::get($key, $default);
    }
}

if (! function_exists('badge_class')) {
    function badge_class(?string $s): string
    {
        return match (strtolower((string) $s)) {
            'paid', 'completed', 'delivered', 'active', 'in', 'investment', 'yes' => 'bg-emerald-100 text-emerald-800',
            'partial', 'partially paid', 'in_progress', 'repair', 'weekly', 'biweekly' => 'bg-amber-100 text-amber-800',
            'unpaid', 'pending', 'out', 'withdrawal', 'cancelled', 'scrapped', 'advance' => 'bg-rose-100 text-rose-800',
            default => 'bg-slate-100 text-slate-700',
        };
    }
}

if (! function_exists('label')) {
    function label(?string $s): string
    {
        return $s === null ? '—' : __(ucwords(str_replace('_', ' ', $s)));
    }
}

if (! function_exists('cell_text')) {
    /** Resolve a column definition [label, key|closure, opts] against a row into plain text. */
    function cell_text($row, array $col): string
    {
        $src = $col[1];
        $v = $src instanceof Closure ? $src($row) : data_get($row, $src);
        $o = $col[2] ?? [];
        if ($v instanceof \DateTimeInterface) {
            return fmt_date($v);
        }
        if (! empty($o['money'])) {
            return money($v ?? 0);
        }
        if (! empty($o['label'])) {
            return label($v);
        }
        return $v === null || $v === '' ? '—' : (string) $v;
    }
}

if (! function_exists('opts')) {
    /** [id => label] option list for selects. */
    function opts(string $class, string $col = 'name', ?Closure $scope = null): array
    {
        $q = $class::query()->orderBy($col);
        if ($scope) {
            $scope($q);
        }
        return $q->pluck($col, 'id')->all();
    }
}

if (! function_exists('brand_url')) {
    /** URL of an admin-uploaded brand asset (Settings → Branding), or the bundled default. */
    function brand_url(string $key, string $default): string
    {
        $path = biz("brand_$key");

        return $path ? \Illuminate\Support\Facades\Storage::url($path).'?v='.biz('brand_version', 1) : $default;
    }
}

if (! function_exists('compact_num')) {
    /** 1,284 / 12.5K / 1.2M — for chart axes and tips. */
    function compact_num($v): string
    {
        $v = (float) $v;
        $a = abs($v);
        if ($a >= 1_000_000) {
            $s = number_format($v / 1_000_000, 1);

            return (str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s).'M';
        }
        if ($a >= 1_000) {
            $s = number_format($v / 1_000, $a >= 100_000 ? 0 : 1);

            return (str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s).'K';
        }

        return number_format($v);
    }
}

if (! function_exists('nice_max')) {
    /** Round an axis maximum up to 1 / 2 / 2.5 / 5 × 10ⁿ so ticks land on clean numbers. */
    function nice_max(float $max): float
    {
        if ($max <= 0) {
            return 1;
        }
        $pow = 10 ** floor(log10($max));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $pow >= $max) {
                return $m * $pow;
            }
        }

        return 10 * $pow;
    }
}
