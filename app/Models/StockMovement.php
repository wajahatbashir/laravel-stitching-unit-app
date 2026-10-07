<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use Tracked;

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date'];

    public function item() { return $this->belongsTo(InventoryItem::class, 'item_id'); }
    public function order() { return $this->belongsTo(Order::class); }
}
