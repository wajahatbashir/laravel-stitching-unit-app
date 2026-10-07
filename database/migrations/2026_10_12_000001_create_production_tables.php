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
    public function up(): void
    {
        // pieces that completed a stage on a day (cutting / stitching / finishing / quality / packing)
        Schema::create('production_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('stage', 20);
            $t->date('date');
            $t->unsignedInteger('qty');
            $t->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['order_id', 'stage']);
        });

        // rejected (lost) pieces or pieces sent back for rework, optionally charged to the responsible worker
        Schema::create('production_rejects', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10)->default('reject'); // reject | rework
            $t->string('stage', 20)->nullable();       // where the fault was found
            $t->date('date');
            $t->unsignedInteger('qty');
            $t->foreignId('worker_id')->nullable()->constrained()->nullOnDelete(); // responsible
            $t->string('reason')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // deductions (rejects, advances recovered…) and bonuses that change what a worker has earned
        Schema::create('worker_adjustments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('worker_id')->constrained();
            $t->string('type', 10)->default('deduction'); // deduction | bonus
            $t->date('date');
            $t->decimal('amount', 14, 2);
            $t->string('reason')->nullable();
            $t->foreignId('reject_id')->nullable()->constrained('production_rejects')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['worker_id', 'date']);
        });

        // one challan = one batch of one order sent to the brand
        Schema::create('deliveries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->string('challan_no')->unique();
            $t->foreignId('order_id')->constrained();
            $t->date('date');
            $t->unsignedInteger('qty');
            $t->string('vehicle')->nullable();
            $t->string('received_by')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['production.view', 'production.create', 'production.edit', 'production.delete',
            'deliveries.view', 'deliveries.create', 'deliveries.edit', 'deliveries.delete', 'reports.production', 'reports.quality'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        if ($admin = Role::where('name', 'Admin')->where('guard_name', 'web')->first()) {
            $admin->givePermissionTo($perms);
        }
        if ($user = Role::where('name', 'User')->where('guard_name', 'web')->first()) {
            $user->givePermissionTo(['production.view', 'production.create']);
        }
        foreach (['order_overdue', 'delivery_created'] as $event) {
            foreach (['database' => true, 'mail' => false, 'whatsapp' => false] as $channel => $on) {
                NotificationSetting::firstOrCreate(['event' => $event, 'channel' => $channel], ['enabled' => $on && $event === 'order_overdue']);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['deliveries', 'worker_adjustments', 'production_rejects', 'production_logs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
