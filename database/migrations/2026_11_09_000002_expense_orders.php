<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One material purchase shared by several orders. A single-order expense keeps using expenses.order_id;
 * an expense covering 2+ orders has order_id = NULL and one row per order here (its share, in the expense
 * currency and in the base currency; the shares always add up to the expense). Only the order costing
 * changes — the expense itself, cash/bank, vendor bills and the P&L are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->decimal('base_amount', 14, 2);
            $t->timestamps();
            $t->unique(['expense_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_orders');
    }
};
