<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer payment recorded without an invoice is an *advance* (money on account).
     * An allocation applies part of an advance to an invoice, so history stays traceable:
     * one advance can pay several invoices, one invoice can use several advances.
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_payment_id')->constrained('customer_payments')->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $t->date('date');
            $t->decimal('amount', 14, 2);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
