<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use Tracked;

    public const STATUSES = ['pending', 'in_progress', 'completed', 'delivered', 'cancelled'];

    public static bool $hasUuid = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2', 'rate' => 'decimal:2'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function brand() { return $this->belongsTo(Brand::class); }

    /** "Maria.B. · Eid Luxe" — brand and collection on one line, whichever are filled. */
    public function getBrandCollectionAttribute(): string
    {
        return collect([$this->brand?->name, $this->collection_name])->filter()->implode(' · ');
    }
    public function expenses() { return $this->hasMany(Expense::class); }
    /** Purchases shared with other orders (this order's share is in the pivot). */
    public function sharedExpenses() { return $this->belongsToMany(Expense::class, 'expense_orders')->withoutGlobalScopes()->withPivot('amount', 'base_amount'); }
    public function workEntries() { return $this->hasMany(WorkEntry::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function attachments() { return $this->morphMany(Attachment::class, 'attachable'); }

    /**
     * Next order number. With a customer that has an order code (SWA, HUM …) it continues that customer's own
     * sequence — SWA-0006 → SWA-0007; a brand-new customer starts at 0001. Without a customer/code it falls back
     * to the shared prefix from Settings (ORD-0001 …). Always unique across the whole table.
     */
    public static function nextNumber(?int $customerId = null): string
    {
        $code = $customerId ? Customer::withoutGlobalScopes()->whereKey($customerId)->value('order_code') : null;

        if ($code) {
            $prefix = $code.'-';
            $n = static::withoutGlobalScopes()->where('customer_id', $customerId)->where('order_no', 'like', $prefix.'%')
                ->pluck('order_no')
                ->map(fn ($no) => ctype_digit($tail = substr($no, strlen($prefix))) ? (int) $tail : 0)
                ->max() + 1;
        } else {
            $prefix = Setting::get('order_prefix', 'ORD-');
            $n = (int) static::withoutGlobalScopes()->max('id') + 1;
        }

        do {
            $no = $prefix.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (static::withoutGlobalScopes()->where('order_no', $no)->exists());

        return $no;
    }

    public function materialCost(): float
    {
        return round((float) $this->expenses()->withoutGlobalScopes()->sum('base_amount')
            + (float) \Illuminate\Support\Facades\DB::table('expense_orders')->where('order_id', $this->id)->sum('base_amount'), 2);
    }

    public function labourCost(): float
    {
        return (float) $this->workEntries()->withoutGlobalScopes()->sum('amount');
    }
}
