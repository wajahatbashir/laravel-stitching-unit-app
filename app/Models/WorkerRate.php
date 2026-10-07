<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerRate extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    public function garmentType() { return $this->belongsTo(GarmentType::class); }
}
