<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class ProductionLog extends Model
{
    use Tracked;

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date'];

    public function order() { return $this->belongsTo(Order::class); }
    public function worker() { return $this->belongsTo(Worker::class); }
}
