<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Permissions for the new finance reports: Cash Book, Monthly Summary, Customer Receivables & Ageing. Admins get them; other roles can be given them in Roles & permissions. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['reports.cashbook', 'reports.monthly', 'reports.receivables'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        if ($admin = Role::where('name', 'Admin')->where('guard_name', 'web')->first()) {
            $admin->givePermissionTo($perms);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['reports.cashbook', 'reports.monthly', 'reports.receivables'])->delete();
    }
};
