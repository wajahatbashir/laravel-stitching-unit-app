<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class WorkerPayment extends Model
{
    use LocksPeriod, Tracked;

    /** Records are frozen once the month of this column is closed (see PeriodLock). */
    public static string $lockColumn = 'date';

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'period_from' => 'date', 'period_to' => 'date', 'amount' => 'decimal:2'];

    protected static function booted(): void
    {
        // deleting a payment also deletes its payment slip
        static::deleted(function (self $p) {
            if ($p->receipt_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($p->receipt_path);
            }
        });
    }

    public function worker() { return $this->belongsTo(Worker::class); }
}
