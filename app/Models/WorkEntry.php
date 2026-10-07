<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class WorkEntry extends Model
{
    use LocksPeriod, Tracked;

    /** Records are frozen once the month of this column is closed (see PeriodLock). */
    public static string $lockColumn = 'date';

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'rate' => 'decimal:2', 'amount' => 'decimal:2'];

    public function worker() { return $this->belongsTo(Worker::class); }
    public function order() { return $this->belongsTo(Order::class); }
    public function garmentType() { return $this->belongsTo(GarmentType::class); }
}
