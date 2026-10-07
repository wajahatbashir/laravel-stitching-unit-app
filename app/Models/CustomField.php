<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomField extends Model
{
    protected $guarded = [];
    protected $casts = ['required' => 'boolean', 'is_active' => 'boolean'];

    public function optionList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->options))));
    }
}
