<?php

use App\Models\NotificationSetting;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Adds the backup permissions (Admin gets view, only the Super Admin can restore) and the "backup failed" notification event. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['backups.view', 'backups.restore'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        if ($admin = Role::where('name', 'Admin')->where('guard_name', 'web')->first()) {
            $admin->givePermissionTo('backups.view');
        }
        foreach (['database' => true, 'mail' => false, 'whatsapp' => false] as $channel => $on) {
            NotificationSetting::firstOrCreate(['event' => 'backup_failed', 'channel' => $channel], ['enabled' => $on]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['backups.view', 'backups.restore'])->delete();
        NotificationSetting::where('event', 'backup_failed')->delete();
    }
};
