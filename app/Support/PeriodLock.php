<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\PeriodClosure;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Month close. Records whose date falls in a closed month (expenses, payments, salaries, work entries,
 * invoices, investments) cannot be created, edited or deleted — unless an admin who may override
 * supplies a written reason (recorded in the audit log). Enforced from model events, so every entry
 * point (forms, offline sync, generators) is covered.
 */
class PeriodLock
{
    private static ?string $override = null;
    private static ?array $closed = null;

    /** @return array<string,int> 'Y-m' => closure id */
    public static function closedMonths(): array
    {
        return self::$closed ??= PeriodClosure::whereNull('reopened_at')->get()
            ->mapWithKeys(fn ($c) => [$c->month->format('Y-m') => $c->id])->all();
    }

    public static function flush(): void
    {
        self::$closed = null;
    }

    public static function isClosed($date): bool
    {
        return $date && isset(self::closedMonths()[Carbon::parse($date)->format('Y-m')]);
    }

    /** Allow closed-month changes for the current request with this reason (null = back to normal). */
    public static function allow(?string $reason): void
    {
        self::$override = $reason ?: null;
    }

    /** Is this record sitting in a closed month right now? */
    public static function isLocked(Model $m): bool
    {
        $col = $m::$lockColumn;

        return $m->exists && self::isClosed($m->getOriginal($col) ?? $m->{$col});
    }

    /** Called by the LocksPeriod trait before save / delete. */
    public static function guard(Model $m, string $op): void
    {
        $col = $m::$lockColumn;
        $dates = [];
        if ($m->exists) {
            $dates[] = $m->getOriginal($col);
        }
        if ($op === 'save') {
            $dates[] = $m->{$col};
        }
        $months = collect($dates)->filter()->map(fn ($d) => Carbon::parse($d)->format('Y-m'))->unique();

        foreach ($months as $ym) {
            if (! isset(self::closedMonths()[$ym])) {
                continue;
            }
            $label = Carbon::createFromFormat('Y-m-d', "$ym-01")->translatedFormat('F Y');
            if (self::$override) {
                AuditLog::create(['user_id' => auth()->id(), 'action' => 'override', 'model' => class_basename($m), 'model_id' => $m->getKey(),
                    'changes' => ['month' => $label, 'operation' => $op, 'reason' => self::$override], 'ip' => request()?->ip()]);

                continue;
            }
            throw ValidationException::withMessages(['period' => $op === 'delete'
                ? __(':m is closed, so this record cannot be deleted. Re-open the month first (Admin → Month close).', ['m' => $label])
                : __(':m is closed — records dated in it cannot be added or changed. An admin can override with a reason, or re-open the month.', ['m' => $label])]);
        }
    }
}
