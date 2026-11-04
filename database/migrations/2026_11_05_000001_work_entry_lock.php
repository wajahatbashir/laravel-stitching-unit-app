<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Manual lock for work entries: an admin sets an entry (or many at once) to "closed" once it is settled; a closed entry
 * can then only be changed, re-opened or deleted by a Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_entries', function (Blueprint $t) {
            $t->string('status', 10)->default('open')->after('notes');   // open | closed
            $t->timestamp('closed_at')->nullable()->after('status');
            $t->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $t->index('status');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('work_entries.close', 'web');
        if ($admin = Role::where('name', 'Admin')->where('guard_name', 'web')->first()) {
            $admin->givePermissionTo('work_entries.close');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('work_entries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('closed_by');
            $t->dropColumn(['status', 'closed_at']);
        });
    }
};
