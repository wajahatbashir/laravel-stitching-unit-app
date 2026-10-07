<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use Tracked;

    public static bool $hasUuid = true;
    protected $guarded = [];
    protected $casts = ['purchase_date' => 'date', 'cost' => 'decimal:2'];

    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function expenses() { return $this->hasMany(Expense::class); }
    public function attachments() { return $this->morphMany(Attachment::class, 'attachable'); }
}
