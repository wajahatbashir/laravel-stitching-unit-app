<?php

use App\Models\NotificationSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Buying on credit: an expense with payment_mode = 'credit' is a bill we owe the vendor (counted as a cost
     * straight away, but no cash leaves until we pay). Money paid to the vendor is a vendor payment.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->date('due_date')->nullable()->after('payment_mode'); // when a credit bill must be paid
        });

        Schema::create('vendor_payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('vendor_id')->constrained();
            $t->date('date');
            $t->decimal('amount', 14, 2);
            $t->string('mode', 10)->default('bank'); // cash | bank
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['vendor_id', 'date']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['vendor_payments.view', 'vendor_payments.create', 'vendor_payments.edit', 'vendor_payments.delete', 'reports.vendor_payables'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        if ($admin = Role::where('name', 'Admin')->where('guard_name', 'web')->first()) {
            $admin->givePermissionTo($perms);
        }
        foreach (['database' => true, 'mail' => false, 'whatsapp' => false] as $channel => $on) {
            NotificationSetting::firstOrCreate(['event' => 'vendor_bill_due', 'channel' => $channel], ['enabled' => $on]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
        Schema::table('expenses', fn (Blueprint $t) => $t->dropColumn('due_date'));
    }
};
