<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class SalaryEntry extends Model
{
    use LocksPeriod, Tracked;

    /** Records are frozen once the month of this column is closed (see PeriodLock). */
    public static string $lockColumn = 'period_month';

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['period_month' => 'date'];

    public function worker() { return $this->belongsTo(Worker::class); }
    public function net(): float { return (float) $this->amount + (float) $this->bonus - (float) $this->deduction; }
}
