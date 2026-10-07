<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

/** The date range the dashboard is showing: this/previous week, month, year, or a custom range. */
class DashboardPeriod
{
    public const KEYS = ['week', 'month', 'year', 'custom'];

    public function __construct(
        public string $key,
        public int $offset,
        public Carbon $from,
        public Carbon $to,
    ) {
    }

    public static function resolve(Request $r): self
    {
        $key = in_array($r->query('period'), self::KEYS, true) ? $r->query('period') : 'month';
        $offset = min(0, max(-120, (int) $r->query('offset', 0)));

        if ($key === 'custom') {
            try {
                $from = Carbon::parse((string) $r->query('from'))->startOfDay();
                $to = Carbon::parse((string) $r->query('to'))->endOfDay();
                if ($from->gt($to)) {
                    [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
                }
                if ($from->diffInDays($to) > 366 * 5) {
                    $to = $from->copy()->addYears(5)->endOfDay();
                }

                return new self('custom', 0, $from, $to);
            } catch (\Throwable) {
                $key = 'month'; // unreadable dates → fall back to this month
            }
        }
        [$from, $to] = self::range($key, $offset);

        return new self($key, $offset, $from, $to);
    }

    /** [from, to] for a named period, $offset periods back from now (0 = current). */
    public static function range(string $key, int $offset): array
    {
        $base = match ($key) {
            'week' => now()->startOfWeek()->addWeeks($offset),
            'year' => now()->startOfYear()->addYears($offset),
            default => now()->startOfMonth()->addMonthsNoOverflow($offset),
        };
        $end = match ($key) {
            'week' => $base->copy()->endOfWeek(),
            'year' => $base->copy()->endOfYear(),
            default => $base->copy()->endOfMonth(),
        };

        return [$base->startOfDay(), $end->endOfDay()];
    }

    /** Number of calendar days in the period (whole days, both ends included). */
    public function days(): int
    {
        return (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    /** The equally long period right before this one (for "vs previous" comparisons). */
    public function previous(): array
    {
        if ($this->key !== 'custom') {
            return self::range($this->key, $this->offset - 1);
        }
        $days = $this->days();
        $to = $this->from->copy()->subDay()->endOfDay();

        return [$to->copy()->subDays($days - 1)->startOfDay(), $to];
    }

    /** Chart granularity that keeps the number of bars readable. */
    public function bucket(): string
    {
        $days = $this->days();

        return $days <= 10 ? 'day' : ($days <= 100 ? 'week' : 'month');
    }

    /** Charts never extend into the future. */
    public function chartTo(): Carbon
    {
        return $this->to->gt(now()) ? now()->endOfDay() : $this->to->copy();
    }

    public function label(): string
    {
        $sameDay = $this->from->isSameDay($this->to);

        return match (true) {
            $this->key === 'month' => $this->from->translatedFormat('F Y'),
            $this->key === 'year' => $this->from->format('Y'),
            $sameDay => $this->from->translatedFormat('d M Y'),
            $this->from->year === $this->to->year => $this->from->translatedFormat('d M').' – '.$this->to->translatedFormat('d M Y'),
            default => $this->from->translatedFormat('d M Y').' – '.$this->to->translatedFormat('d M Y'),
        };
    }

    public function url(array $q = []): string
    {
        return route('dashboard', array_filter($q, fn ($v) => $v !== null && $v !== ''));
    }
}
