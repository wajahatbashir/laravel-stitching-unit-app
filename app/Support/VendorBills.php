<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\VendorPayment;

/**
 * Payment status of every credit bill (fully or partly on credit). A vendor's payments clear their bills
 * oldest first, so a bill is Paid, Partly paid, Unpaid, or Overdue (unpaid part past its due date).
 */
class VendorBills
{
    /** @return array<int,array{credit:float,paid:float,left:float,status:string,due:\Carbon\Carbon}> keyed by expense id */
    public static function map(?int $vendorId = null): array
    {
        $pool = VendorPayment::withoutGlobalScopes()->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->selectRaw('vendor_id, SUM(amount) as total')->groupBy('vendor_id')->pluck('total', 'vendor_id')->map(fn ($v) => (float) $v)->all();

        $out = [];
        $today = now()->startOfDay();
        $bills = Expense::withoutGlobalScopes()->whereIn('payment_mode', Expense::CREDIT_MODES)->whereNotNull('vendor_id')
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))->orderBy('vendor_id')->orderBy('date')->orderBy('id')->get();
        foreach ($bills as $e) {
            $credit = $e->creditAmount();
            $used = min($pool[$e->vendor_id] ?? 0.0, $credit);
            $pool[$e->vendor_id] = ($pool[$e->vendor_id] ?? 0.0) - $used;
            $left = round($credit - $used, 2);
            $due = ($e->due_date ?? $e->date->copy()->addDays(30))->copy()->startOfDay();
            $out[$e->id] = [
                'credit' => $credit, 'paid' => round($used, 2), 'left' => $left, 'due' => $due,
                'status' => $left <= 0.004 ? 'paid' : ($due->lt($today) ? 'overdue' : ($used > 0 ? 'partial' : 'unpaid')),
            ];
        }

        return $out;
    }

    public static function label(string $status): string
    {
        return ['paid' => __('Paid'), 'partial' => __('Partly paid'), 'unpaid' => __('Unpaid'), 'overdue' => __('Overdue')][$status] ?? $status;
    }
}
