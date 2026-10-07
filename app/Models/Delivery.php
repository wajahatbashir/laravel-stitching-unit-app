<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    use Tracked;

    public static bool $hasUuid = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date'];

    public function order() { return $this->belongsTo(Order::class); }

    public static function nextNumber(): string
    {
        $prefix = Setting::get('challan_prefix', 'DC-');
        $n = (int) static::max('id') + 1;
        do {
            $no = $prefix.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (static::where('challan_no', $no)->exists());

        return $no;
    }
}
