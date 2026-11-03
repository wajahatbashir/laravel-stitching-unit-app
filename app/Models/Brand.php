<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A fashion label whose collections are stitched here (Maria.B., Noore, Parishay …). */
class Brand extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function orders() { return $this->hasMany(Order::class); }
}
