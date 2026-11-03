<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Partly on credit": part of an expense is paid at purchase (paid_now via paid_via), the rest becomes a vendor bill. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->decimal('paid_now', 14, 2)->nullable()->after('payment_mode'); // in the expense's own currency, like amount
            $t->string('paid_via', 10)->nullable()->after('paid_now');         // cash | bank | other
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropColumn(['paid_now', 'paid_via']);
        });
    }
};
