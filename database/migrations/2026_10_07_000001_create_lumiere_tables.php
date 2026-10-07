<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function base(Blueprint $t, bool $uuid = false): void
    {
        $t->id();
        if ($uuid) {
            $t->uuid('uuid')->nullable()->unique();
        }
    }

    private function audit(Blueprint $t): void
    {
        $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $t->timestamps();
    }

    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('phone')->nullable();
            $t->string('locale', 5)->default('en');
            $t->boolean('is_active')->default(true);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
        });

        Schema::create('notification_settings', function (Blueprint $t) {
            $t->id();
            $t->string('event');
            $t->string('channel'); // database | mail | whatsapp
            $t->boolean('enabled')->default(true);
            $t->unique(['event', 'channel']);
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action'); // created | updated | deleted
            $t->string('model');
            $t->unsignedBigInteger('model_id')->nullable();
            $t->json('changes')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('currencies', function (Blueprint $t) {
            $t->id();
            $t->string('code', 5)->unique();
            $t->string('name');
            $t->string('symbol', 8);
            $t->decimal('rate', 18, 6)->default(1); // 1 unit = rate × base currency
            $t->boolean('is_base')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        foreach (['customers', 'vendors'] as $name) {
            Schema::create($name, function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('contact_person')->nullable();
                $t->string('phone')->nullable();
                $t->string('email')->nullable();
                $t->text('address')->nullable();
                $t->text('notes')->nullable();
                $t->boolean('is_active')->default(true);
                $this->audit($t);
            });
        }

        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->string('type'); // order | unit | labour | other
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['type', 'name']);
        });

        Schema::create('garment_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->decimal('default_rate', 12, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('custom_fields', function (Blueprint $t) {
            $t->id();
            $t->string('entity')->default('unit_expense');
            $t->string('label');
            $t->string('key');
            $t->string('type')->default('text'); // text number date image select textarea
            $t->text('options')->nullable(); // comma separated for select
            $t->boolean('required')->default(false);
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['entity', 'key']);
        });

        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->morphs('attachable');
            $t->string('path');
            $t->string('name');
            $t->string('mime')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('assets', function (Blueprint $t) {
            $this->base($t, true);
            $t->string('type')->default('machine'); // machine | furniture | other
            $t->string('name');
            $t->string('asset_no')->nullable(); // machine number
            $t->string('brand')->nullable();
            $t->string('model')->nullable();
            $t->string('serial_no')->nullable();
            $t->decimal('cost', 14, 2)->default(0);
            $t->date('purchase_date')->nullable();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->string('location')->nullable();
            $t->string('status')->default('active'); // active | repair | sold | scrapped
            $t->text('notes')->nullable();
            $this->audit($t);
        });

        Schema::create('investors', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $this->audit($t);
        });

        Schema::create('investments', function (Blueprint $t) {
            $this->base($t, true);
            $t->foreignId('investor_id')->constrained();
            $t->string('type')->default('investment'); // investment | withdrawal
            $t->string('mode')->default('bank'); // bank | cash
            $t->date('date');
            $t->decimal('amount', 14, 2);
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $this->audit($t);
        });

        Schema::create('orders', function (Blueprint $t) {
            $this->base($t, true);
            $t->string('order_no')->unique();
            $t->date('date');
            $t->foreignId('customer_id')->constrained();
            $t->string('collection_name')->nullable();
            $t->string('fabric_name')->nullable();
            $t->unsignedInteger('qty')->default(0);
            $t->decimal('rate', 12, 2)->default(0); // billing rate per piece
            $t->decimal('amount', 14, 2)->default(0);
            $t->date('due_date')->nullable();
            $t->string('status')->default('pending'); // pending | in_progress | completed | delivered | cancelled
            $t->text('notes')->nullable();
            $this->audit($t);
        });

        Schema::create('expenses', function (Blueprint $t) {
            $this->base($t, true);
            $t->string('type'); // order | unit | labour | other
            $t->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date');
            $t->string('title')->nullable();
            $t->string('payee')->nullable();
            $t->decimal('qty', 12, 2)->nullable();
            $t->decimal('rate', 12, 2)->nullable();
            $t->decimal('amount', 14, 2);
            $t->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('exchange_rate', 18, 6)->default(1);
            $t->decimal('base_amount', 14, 2);
            $t->string('payment_mode')->default('cash'); // cash | bank | other
            $t->string('receipt_path')->nullable();
            $t->longText('ocr_text')->nullable();
            $t->json('custom')->nullable();
            $t->text('notes')->nullable();
            $this->audit($t);
            $t->index(['type', 'date']);
        });

        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('unit')->default('pcs');
            $t->decimal('min_stock', 12, 2)->default(0);
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $this->audit($t);
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $this->base($t, true);
            $t->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $t->string('type'); // in | out
            $t->date('date');
            $t->decimal('qty', 12, 2);
            $t->decimal('rate', 12, 2)->default(0);
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->text('notes')->nullable();
            $this->audit($t);
        });

        Schema::create('invoices', function (Blueprint $t) {
            $this->base($t, true);
            $t->string('number')->unique();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date');
            $t->date('due_date')->nullable();
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->text('notes')->nullable();
            $this->audit($t);
        });

        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->decimal('qty', 12, 2)->default(1);
            $t->decimal('rate', 12, 2)->default(0);
            $t->decimal('amount', 14, 2)->default(0);
        });

        Schema::create('customer_payments', function (Blueprint $t) {
            $this->base($t, true);
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date');
            $t->decimal('amount', 14, 2);
            $t->string('mode')->default('bank'); // cash | bank
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $this->audit($t);
        });

        Schema::create('workers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->string('cnic')->nullable();
            $t->string('type')->default('freelancer'); // freelancer | salaried
            $t->string('pay_cycle')->default('weekly'); // weekly | biweekly | monthly
            $t->decimal('monthly_salary', 12, 2)->default(0);
            $t->date('joined_at')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $this->audit($t);
        });

        Schema::create('worker_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $t->foreignId('garment_type_id')->constrained()->cascadeOnDelete();
            $t->decimal('rate', 12, 2);
            $t->unique(['worker_id', 'garment_type_id']);
        });

        Schema::create('work_entries', function (Blueprint $t) {
            $this->base($t, true);
            $t->foreignId('worker_id')->constrained();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('garment_type_id')->constrained();
            $t->date('date');
            $t->unsignedInteger('qty');
            $t->decimal('rate', 12, 2); // snapshot at entry time
            $t->decimal('amount', 14, 2);
            $t->text('notes')->nullable();
            $this->audit($t);
            $t->index(['worker_id', 'date']);
        });

        Schema::create('salary_entries', function (Blueprint $t) {
            $this->base($t, true);
            $t->foreignId('worker_id')->constrained();
            $t->date('period_month'); // first day of month
            $t->decimal('amount', 12, 2);
            $t->decimal('bonus', 12, 2)->default(0);
            $t->decimal('deduction', 12, 2)->default(0);
            $t->text('notes')->nullable();
            $this->audit($t);
            $t->unique(['worker_id', 'period_month']);
        });

        Schema::create('worker_payments', function (Blueprint $t) {
            $this->base($t, true);
            $t->foreignId('worker_id')->constrained();
            $t->string('type')->default('payment'); // payment | advance
            $t->date('date');
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->decimal('amount', 14, 2);
            $t->string('mode')->default('cash'); // cash | bank
            $t->text('notes')->nullable();
            $this->audit($t);
            $t->index(['worker_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['worker_payments', 'salary_entries', 'work_entries', 'worker_rates', 'workers', 'customer_payments',
            'invoice_items', 'invoices', 'stock_movements', 'inventory_items', 'expenses', 'orders', 'investments',
            'investors', 'assets', 'attachments', 'custom_fields', 'garment_types', 'expense_categories', 'vendors',
            'customers', 'currencies', 'audit_logs', 'notifications', 'notification_settings', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['phone', 'locale', 'is_active']));
    }
};
