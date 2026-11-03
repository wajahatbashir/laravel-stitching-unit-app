<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Proof of payment (bank screenshot, deposit slip, cheque photo) attached to a customer payment / advance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $t) {
            $t->string('receipt_path')->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $t) {
            $t->dropColumn('receipt_path');
        });
    }
};
