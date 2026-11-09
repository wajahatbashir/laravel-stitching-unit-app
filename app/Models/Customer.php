<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use Tracked;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    /** Suggested order code from a name: first 3 letters/digits, made unique (SWA, HUM, HUM2 …). */
    public static function suggestCode(string $name, ?int $ignoreId = null): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 3)) ?: 'CUS';
        $code = $base;
        for ($i = 2; static::withoutGlobalScopes()->where('order_code', $code)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $code = $base.$i;
        }

        return $code;
    }

    public function orders() { return $this->hasMany(Order::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function payments() { return $this->hasMany(CustomerPayment::class); }

    /** Money still owed on invoices (after direct payments and applied advances). */
    public function receivable(): float
    {
        $direct = (float) $this->payments()->withoutGlobalScopes()->whereNotNull('invoice_id')->sum('amount');
        $applied = (float) PaymentAllocation::whereIn('invoice_id', $this->invoices()->select('id'))->sum('amount');

        return round((float) $this->invoices()->sum('total') - $direct - $applied, 2);
    }

    /** Unused advance money held for this customer. */
    public function advance(): float
    {
        return \App\Support\Advances::available($this->id);
    }

}
