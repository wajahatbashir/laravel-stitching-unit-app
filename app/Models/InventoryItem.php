<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use Tracked;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
    public function movements() { return $this->hasMany(StockMovement::class, 'item_id'); }
    public function stock(): float
    {
        return (float) $this->movements()->where('type', 'in')->sum('qty') - (float) $this->movements()->where('type', 'out')->sum('qty');
    }

}
