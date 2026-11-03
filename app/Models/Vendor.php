<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use Tracked;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function expenses() { return $this->hasMany(Expense::class)->withoutGlobalScopes(); }
    public function payments() { return $this->hasMany(VendorPayment::class); }

    /** Bills bought on credit, fully or partly (the part not paid when they were recorded). */
    public function creditBills() { return $this->expenses()->whereIn('payment_mode', Expense::CREDIT_MODES); }

    public function billed(): float
    {
        return round((float) $this->creditBills()->sum(\DB::raw("CASE WHEN payment_mode = 'partial' THEN base_amount - COALESCE(paid_now, 0) * exchange_rate ELSE base_amount END")), 2);
    }

    public function paid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /** What we owe the vendor (negative = we have paid ahead). */
    public function payable(): float
    {
        return round($this->billed() - $this->paid(), 2);
    }

    /**
     * Unpaid part of each credit bill, oldest first: payments are applied to the oldest bills first.
     *
     * @return array<int,array{expense:Expense,left:float,due:\Carbon\Carbon}>
     */
    public function openBills(): array
    {
        $credit = $this->paid();
        $open = [];
        foreach ($this->creditBills()->orderBy('date')->orderBy('id')->get() as $e) {
            $bill = $e->creditAmount();
            $used = min($credit, $bill);
            $credit -= $used;
            if ($bill - $used > 0.004) {
                $open[] = ['expense' => $e, 'left' => round($bill - $used, 2), 'due' => ($e->due_date ?? $e->date->copy()->addDays(30))->copy()];
            }
        }

        return $open;
    }
}
