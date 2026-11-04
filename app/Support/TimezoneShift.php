<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * One-time move of stored date+time values when the app time zone changes (UTC → Pakistan time, +5 h).
 * Plain dates (invoice date, expense date …) are untouched; only date+time columns move.
 *
 * Not moved: the migrations table, failed_jobs.failed_at, and audit_logs.created_at — the audit log gets its time from the
 * database clock (already local time) — except the few rows PHP wrote itself (action "reset").
 */
class TimezoneShift
{
    private const SKIP_TABLES = ['migrations', 'failed_jobs', 'job_batches', 'jobs', 'cache', 'cache_locks', 'sessions'];

    /** @return array<string,string[]> table => date+time columns */
    public static function columns(): array
    {
        $rows = DB::select("select table_name t, column_name c from information_schema.columns
            where table_schema = database() and data_type in ('timestamp', 'datetime') order by table_name, column_name");

        $out = [];
        foreach ($rows as $r) {
            if (! in_array($r->t, self::SKIP_TABLES, true) && ! ($r->t === 'audit_logs' && $r->c === 'created_at')) {
                $out[$r->t][] = $r->c;
            }
        }

        return $out;
    }

    /**
     * Add (or, negative, subtract) whole hours to every stored date+time.
     *
     * @return array<string,int> table => rows touched (largest column count)
     */
    public static function apply(int $hours): array
    {
        $touched = [];
        DB::transaction(function () use ($hours, &$touched) {
            foreach (self::columns() as $table => $cols) {
                foreach ($cols as $c) {
                    $n = DB::update("update `$table` set `$c` = date_add(`$c`, interval $hours hour) where `$c` is not null");
                    $touched[$table] = max($touched[$table] ?? 0, $n);
                }
            }
            DB::update("update `audit_logs` set `created_at` = date_add(`created_at`, interval $hours hour) where `action` = 'reset'");
        });

        return $touched;
    }
}
