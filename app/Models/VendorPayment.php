<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

/** Money paid to a vendor against what we owe them (credit purchases). */
class VendorPayment extends Model
{
    use LocksPeriod, Tracked;

    public static string $lockColumn = 'date';
    public static bool $hasUuid = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function vendor() { return $this->belongsTo(Vendor::class); }
}
