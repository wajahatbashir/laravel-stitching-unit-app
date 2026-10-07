<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // month close: a month with an active (not re-opened) row is locked for money records
        Schema::create('period_closures', function (Blueprint $t) {
            $t->id();
            $t->date('month')->index(); // first day of the closed month
            $t->string('note')->nullable();
            $t->timestamp('reopened_at')->nullable();
            $t->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reopen_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // who closed it
            $t->timestamps();
        });

        // two-factor sign-in (authenticator app + recovery codes)
        Schema::table('users', function (Blueprint $t) {
            $t->text('two_factor_secret')->nullable();          // encrypted
            $t->text('two_factor_recovery_codes')->nullable();  // json of hashed codes, encrypted
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->unsignedBigInteger('two_factor_last_ts')->nullable(); // blocks re-using the same code
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['period.close', 'period.override'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        if ($admin = Role::where('name', 'Admin')->where('guard_name', 'web')->first()) {
            $admin->givePermissionTo(['period.close', 'period.override']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_ts']));
        Schema::dropIfExists('period_closures');
        Permission::whereIn('name', ['period.close', 'period.override'])->delete();
    }
};
