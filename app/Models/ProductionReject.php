<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class ProductionReject extends Model
{
    use Tracked;

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date'];

    protected static function booted(): void
    {
        // removing a reject also removes the pay deduction that was created from it
        static::deleting(fn (self $r) => $r->adjustment?->delete());
    }

    public function order() { return $this->belongsTo(Order::class); }
    public function worker() { return $this->belongsTo(Worker::class); }
    public function adjustment() { return $this->hasOne(WorkerAdjustment::class, 'reject_id'); }
}
