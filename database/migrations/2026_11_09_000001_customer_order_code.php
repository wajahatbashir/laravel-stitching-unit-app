<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer order numbers: every customer gets a short unique code (SWA, HUM …) and its orders are
 * numbered SWA-0006, SWA-0007 … on their own sequence. Existing customers get a code and their old
 * "ORD-0006" style numbers are renamed to the new style (same digits). Only labels change — everything
 * links by internal id. Uses the query builder on purpose (no audit rows, no model events).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('order_code', 10)->nullable()->unique()->after('name');
        });

        $taken = [];
        foreach (DB::table('customers')->orderBy('id')->get(['id', 'name']) as $c) {
            $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $c->name), 0, 3)) ?: 'CUS';
            $code = $base;
            for ($i = 2; in_array($code, $taken, true); $i++) {
                $code = $base.$i;
            }
            $taken[] = $code;
            DB::table('customers')->where('id', $c->id)->update(['order_code' => $code]);
        }

        $prefix = (string) (DB::table('settings')->where('key', 'order_prefix')->value('value') ?: 'ORD-');
        foreach (DB::table('orders')->whereNotNull('customer_id')->orderBy('id')->get(['id', 'order_no', 'customer_id']) as $o) {
            if (! str_starts_with($o->order_no, $prefix) || ! ctype_digit(substr($o->order_no, strlen($prefix)))) {
                continue; // custom / already renamed numbers are left alone
            }
            $code = DB::table('customers')->where('id', $o->customer_id)->value('order_code');
            $digits = (int) substr($o->order_no, strlen($prefix));
            DB::table('orders')->where('id', $o->id)->update(['order_no' => $code.'-'.str_pad((string) $digits, 4, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        // order numbers are not reverted (the old ORD- labels are in the pre-migration backup)
        Schema::table('customers', function (Blueprint $t) {
            $t->dropUnique(['order_code']);
            $t->dropColumn('order_code');
        });
    }
};
