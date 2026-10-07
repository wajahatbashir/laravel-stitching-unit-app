<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Investor extends Model
{
    use Tracked;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
    public function investments() { return $this->hasMany(Investment::class); }

}
