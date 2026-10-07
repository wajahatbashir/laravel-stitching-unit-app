<?php

namespace App\Support;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Customer advances: payments recorded without an invoice, applied to invoices through allocations. */
class Advances
{
    /** Unused advance money held for a customer. */
    public static function available(int $customerId): float
    {
        $paid = (float) CustomerPayment::withoutGlobalScopes()->where('customer_id', $customerId)->whereNull('invoice_id')->sum('amount');
        $used = (float) PaymentAllocation::whereIn('customer_payment_id',
            CustomerPayment::withoutGlobalScopes()->where('customer_id', $customerId)->whereNull('invoice_id')->select('id'))->sum('amount');

        return round($paid - $used, 2);
    }

    /** Advance payments of a customer with how much of each is used / still free (oldest first). */
    public static function payments(int $customerId)
    {
        return CustomerPayment::withoutGlobalScopes()->with('allocations.invoice')
            ->where('customer_id', $customerId)->whereNull('invoice_id')->orderBy('date')->orderBy('id')->get();
    }

    /**
     * Apply $amount of the customer's advances to an invoice (oldest advance first).
     * Throws a validation error if it exceeds the available advance or the invoice balance.
     */
    public static function apply(Invoice $inv, float $amount, string $field = 'advance_amount'): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return 0.0;
        }
        $free = self::available($inv->customer_id);
        if ($amount > $free + 0.004) {
            throw ValidationException::withMessages([$field => __('Only :a advance is available for this customer.', ['a' => money($free)])]);
        }
        if ($amount > $inv->balance() + 0.004) {
            throw ValidationException::withMessages([$field => __('Amount is more than the invoice balance (:b).', ['b' => money($inv->balance())])]);
        }

        DB::transaction(function () use ($inv, $amount) {
            $left = $amount;
            foreach (self::payments($inv->customer_id) as $p) {
                $room = round((float) $p->amount - (float) $p->allocations->sum('amount'), 2);
                if ($room <= 0) {
                    continue;
                }
                $take = min($room, $left);
                PaymentAllocation::create(['customer_payment_id' => $p->id, 'invoice_id' => $inv->id, 'date' => now()->toDateString(), 'amount' => $take]);
                $left = round($left - $take, 2);
                if ($left <= 0) {
                    break;
                }
            }
        });

        return $amount;
    }
}
