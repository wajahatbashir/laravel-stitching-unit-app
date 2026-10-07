<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

/** Part of a customer advance applied to an invoice. */
class PaymentAllocation extends Model
{
    use Tracked;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function payment()
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id')->withoutGlobalScopes();
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
