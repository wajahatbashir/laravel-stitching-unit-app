<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class CustomerPayment extends Model
{
    use LocksPeriod, Tracked;

    /** Records are frozen once the month of this column is closed (see PeriodLock). */
    public static string $lockColumn = 'date';

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function allocations() { return $this->hasMany(PaymentAllocation::class); }

    /** Advance (no invoice): how much has been applied to invoices; direct payments are fully applied. */
    public function applied(): float { return $this->invoice_id ? (float) $this->amount : (float) $this->allocations()->sum('amount'); }
    public function unapplied(): float { return round((float) $this->amount - $this->applied(), 2); }
}
