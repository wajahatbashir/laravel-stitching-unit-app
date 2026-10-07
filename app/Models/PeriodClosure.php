<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

/** A month that has been closed. Active while reopened_at is null; re-opening keeps the row as history. */
class PeriodClosure extends Model
{
    use Tracked;

    protected $guarded = [];
    protected $casts = ['month' => 'date', 'reopened_at' => 'datetime'];

    public function closer() { return $this->belongsTo(User::class, 'created_by'); }
    public function reopener() { return $this->belongsTo(User::class, 'reopened_by'); }
}
