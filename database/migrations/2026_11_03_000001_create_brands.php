<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Brands (Maria.B., Noore …) are a managed list; an order picks one Brand and has its own free-text Collection. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('brand_id')->nullable()->after('customer_id')->constrained('brands')->nullOnDelete();
        });

        foreach (['Maria.B.', 'Noore', 'Parishay', 'Ayzel', 'Elaf'] as $name) {
            DB::table('brands')->insertOrIgnore(['name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('brand_id');
        });
        Schema::dropIfExists('brands');
    }
};
