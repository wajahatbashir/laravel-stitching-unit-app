<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cash ⇄ bank transfers: taking cash out of the bank (to pay cash expenses) or depositing cash back.
 * Only moves money between the two pools — it is never a cost and never changes profit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->date('date');
            $t->string('direction', 12);              // bank_to_cash | cash_to_bank
            $t->decimal('amount', 14, 2);
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('date');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['transfers.view', 'transfers.create', 'transfers.edit', 'transfers.delete'];
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
        Schema::dropIfExists('cash_transfers');
    }
};
