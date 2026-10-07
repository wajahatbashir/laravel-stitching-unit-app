<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $guarded = [];
    protected $casts = ['is_base' => 'boolean', 'is_active' => 'boolean'];

    public static function base(): ?self
    {
        return static::where('is_base', true)->first();
    }
}
