<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use LocksPeriod, Tracked;

    /** Records are frozen once the month of this column is closed (see PeriodLock). */
    public static string $lockColumn = 'date';

    public static bool $hasUuid = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'due_date' => 'date'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function order() { return $this->belongsTo(Order::class); }
    public function items() { return $this->hasMany(InvoiceItem::class); }
    public function payments() { return $this->hasMany(CustomerPayment::class); }
    public function allocations() { return $this->hasMany(PaymentAllocation::class); }

    /** Direct payments + advances applied to this invoice. */
    public function paid(): float { return (float) $this->payments()->withoutGlobalScopes()->sum('amount') + (float) $this->allocations()->sum('amount'); }
    public function balance(): float { return (float) $this->total - $this->paid(); }

    public function status(): string
    {
        $paid = $this->paid();
        return $paid <= 0 ? 'unpaid' : ($paid + 0.004 >= (float) $this->total ? 'paid' : 'partial');
    }

    public static function nextNumber(): string
    {
        $prefix = Setting::get('invoice_prefix', 'INV-');
        $n = (int) static::max('id') + 1;
        do {
            $no = $prefix.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (static::where('number', $no)->exists());
        return $no;
    }
}
