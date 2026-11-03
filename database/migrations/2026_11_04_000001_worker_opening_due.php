<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Previous due: what is already owed to a worker from before the app was used (positive), or what they owe (negative). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $t) {
            $t->decimal('opening_due', 14, 2)->default(0)->after('monthly_salary');
            $t->date('opening_date')->nullable()->after('opening_due');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $t) {
            $t->dropColumn(['opening_due', 'opening_date']);
        });
    }
};
