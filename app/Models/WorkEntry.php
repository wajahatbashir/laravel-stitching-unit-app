<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class WorkEntry extends Model
{
    use LocksPeriod, Tracked;

    /** Records are frozen once the month of this column is closed (see PeriodLock). */
    public static string $lockColumn = 'date';

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'rate' => 'decimal:2', 'amount' => 'decimal:2', 'closed_at' => 'datetime'];

    /**
     * Manual lock (status = closed): once an admin closes an entry only a Super Admin may change, re-open or delete it.
     * Enforced here so every path (forms, bulk action, offline sync) obeys it. Scripts / console (no signed-in user) are not blocked.
     */
    protected static function booted(): void
    {
        static::creating(function (self $e) {
            if ($e->status === 'closed' && ($u = auth()->user()) && ! $u->can('work_entries.close')) {
                $e->status = 'open';
            }
        });

        static::updating(function (self $e) {
            if (! ($u = auth()->user())) {
                return;
            }
            $super = $u->hasRole('Super Admin');
            if ($e->getOriginal('status') === 'closed' && ! $super) {
                throw ValidationException::withMessages(['status' => __('This work entry is closed (locked). Only a Super Admin can change or re-open it.')]);
            }
            if ($e->isDirty('status')) {
                if ($e->status === 'closed') {
                    if (! $u->can('work_entries.close')) {
                        throw ValidationException::withMessages(['status' => __('You are not allowed to close work entries.')]);
                    }
                    $e->closed_at = now();
                    $e->closed_by = $u->id;
                } else {
                    $e->closed_at = null;
                    $e->closed_by = null;
                }
            }
        });

        static::deleting(function (self $e) {
            if (($u = auth()->user()) && $e->status === 'closed' && ! $u->hasRole('Super Admin')) {
                throw ValidationException::withMessages(['delete' => __('This work entry is closed (locked). Only a Super Admin can delete it.')]);
            }
        });
    }

    public function isClosed(): bool { return $this->status === 'closed'; }
    public function closer() { return $this->belongsTo(User::class, 'closed_by'); }

    public function worker() { return $this->belongsTo(Worker::class); }
    public function order() { return $this->belongsTo(Order::class); }
    public function garmentType() { return $this->belongsTo(GarmentType::class); }
}
