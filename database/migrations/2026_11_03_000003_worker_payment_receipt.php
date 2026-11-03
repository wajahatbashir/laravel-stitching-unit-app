<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Evidence (bank slip, transfer screenshot) for a payment or advance sent to a worker. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_payments', function (Blueprint $t) {
            $t->string('receipt_path')->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('worker_payments', function (Blueprint $t) {
            $t->dropColumn('receipt_path');
        });
    }
};
