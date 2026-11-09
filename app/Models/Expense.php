<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use LocksPeriod, Tracked;

    /** Records are frozen once the month of this column is closed (see PeriodLock). */
    public static string $lockColumn = 'date';

    public const TYPES = ['order', 'unit', 'labour', 'other'];

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'due_date' => 'date', 'custom' => 'array', 'amount' => 'decimal:2', 'base_amount' => 'decimal:2', 'paid_now' => 'decimal:2'];

    protected static function booted(): void
    {
        // deleting an expense also deletes its receipt and uploaded custom-field images
        static::deleted(function (self $e) {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            foreach (array_merge([$e->receipt_path], array_values((array) $e->custom)) as $p) {
                if (is_string($p) && str_starts_with($p, 'uploads/')) {
                    $disk->delete($p);
                }
            }
        });
    }

    public function category() { return $this->belongsTo(ExpenseCategory::class, 'category_id'); }
    public function order() { return $this->belongsTo(Order::class); }
    /** Orders sharing this purchase (only when it covers 2+ orders; pivot: amount, base_amount). */
    public function sharedOrders() { return $this->belongsToMany(Order::class, 'expense_orders')->withPivot('amount', 'base_amount')->withTimestamps(); }

    /** "SWA-0006" for one order, "SWA-0006, HUM-0002" for a shared purchase. */
    public function orderNumbers(): string
    {
        return $this->order_id
            ? (string) $this->order?->order_no
            : $this->sharedOrders->sortBy('order_no')->pluck('order_no')->implode(', ');
    }

    public function asset() { return $this->belongsTo(Asset::class); }
    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function currency() { return $this->belongsTo(Currency::class); }

    public const CREDIT_MODES = ['credit', 'partial'];

    /** Part paid at purchase, in the base currency (only for "partly on credit"). */
    public function paidNowBase(): float
    {
        return $this->payment_mode === 'partial' ? round((float) $this->paid_now * (float) ($this->exchange_rate ?: 1), 2) : 0.0;
    }

    /** The part that is a bill we owe the vendor (base currency): all of a credit purchase, the unpaid rest of a partly-credit one. */
    public function creditAmount(): float
    {
        return match ($this->payment_mode) {
            'credit' => (float) $this->base_amount,
            'partial' => max(0.0, round((float) $this->base_amount - $this->paidNowBase(), 2)),
            default => 0.0,
        };
    }
}
