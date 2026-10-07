<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

/** A deduction (e.g. rejected pieces) or bonus that changes what a worker has earned. */
class WorkerAdjustment extends Model
{
    use LocksPeriod, Tracked;

    public static string $lockColumn = 'date';
    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function worker() { return $this->belongsTo(Worker::class); }
    public function reject() { return $this->belongsTo(ProductionReject::class, 'reject_id'); }

    /** Effect on earnings: − for a deduction, + for a bonus. */
    public function signed(): float
    {
        return $this->type === 'deduction' ? -(float) $this->amount : (float) $this->amount;
    }
}
