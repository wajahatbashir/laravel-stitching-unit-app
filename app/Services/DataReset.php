<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\PeriodLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * "Start fresh for production": empties all business records (orders, money, labour, uploads, logs) but keeps
 * users, roles, settings, branding and master data. A "pre-reset" backup is taken first, so it can be undone.
 * Every table must be listed in WIPE or KEEP — DataResetTest fails when a new table is added and forgotten.
 */
class DataReset
{
    public const WIPE = [
        'assets', 'attachments', 'audit_logs', 'customer_payments', 'customers', 'deliveries', 'expenses', 'inventory_items',
        'investments', 'investors', 'invoice_items', 'invoices', 'notifications', 'orders', 'payment_allocations', 'period_closures',
        'production_logs', 'production_rejects', 'salary_entries', 'stock_movements', 'vendor_payments', 'vendors', 'work_entries',
        'worker_adjustments', 'worker_payments', 'worker_rates', 'workers',
    ];

    public const KEEP = [
        'cache', 'cache_locks', 'currencies', 'custom_fields', 'expense_categories', 'failed_jobs', 'garment_types', 'job_batches', 'jobs',
        'migrations', 'model_has_permissions', 'model_has_roles', 'notification_settings', 'password_reset_tokens', 'permissions',
        'role_has_permissions', 'roles', 'sessions', 'settings', 'users',
    ];

    /** @return array<string,int> table => rows, for the tables that will be emptied */
    public static function counts(): array
    {
        return collect(self::WIPE)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /** Tables in the current database that are in neither list. */
    public static function unclassified(): array
    {
        $all = array_map(fn ($r) => array_values((array) $r)[0], DB::select('SHOW TABLES'));

        return array_values(array_diff($all, self::WIPE, self::KEEP));
    }

    /** Returns the name of the safety backup. Throws before touching anything if the backup fails. */
    public static function run(User $by): string
    {
        if ($missing = self::unclassified()) {
            throw new \RuntimeException('Unclassified tables, refusing to reset: '.implode(', ', $missing));
        }
        $backup = BackupService::create('pre-reset', $by->name)['name']; // abort here if it fails

        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::WIPE as $t) {
                DB::table($t)->truncate(); // also restarts order / invoice numbering
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        File::deleteDirectory(BackupService::filesDir().DIRECTORY_SEPARATOR.'uploads');
        PeriodLock::flush();

        AuditLog::create(['user_id' => $by->id, 'action' => 'reset', 'model' => 'System', 'model_id' => 0,
            'changes' => ['backup' => $backup], 'ip' => request()?->ip(), 'created_at' => now()]);

        return $backup;
    }
}
