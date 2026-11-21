<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

/** A non-cash settlement of part of an invoice (services / goods the customer provided). Reduces the balance, moves no money. */
class InvoiceAdjustment extends Model
{
    use LocksPeriod, Tracked;

    public static string $lockColumn = 'date';

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
