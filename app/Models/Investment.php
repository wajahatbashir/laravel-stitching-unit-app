<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Investment extends Model
{
    use LocksPeriod, Tracked;

    /** Records are frozen once the month of this column is closed (see PeriodLock). */
    public static string $lockColumn = 'date';

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function investor() { return $this->belongsTo(Investor::class); }
}
