<?php

namespace App\Models\Concerns;

use App\Support\PeriodLock;

/** Blocks changes to records dated in a closed month. The model declares `public static string $lockColumn`. */
trait LocksPeriod
{
    public static function bootLocksPeriod(): void
    {
        static::saving(fn ($m) => PeriodLock::guard($m, 'save'));
        static::deleting(fn ($m) => PeriodLock::guard($m, 'delete'));
    }

    public function isPeriodLocked(): bool
    {
        return PeriodLock::isLocked($this);
    }
}
