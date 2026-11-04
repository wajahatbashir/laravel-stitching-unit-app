<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

/** Cash taken out of the bank (or deposited back): moves money between the two pools, never a cost. */
class CashTransfer extends Model
{
    use LocksPeriod, Tracked;

    public static string $lockColumn = 'date';
    public static bool $hasUuid = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public const DIRECTIONS = ['bank_to_cash', 'cash_to_bank'];
}
