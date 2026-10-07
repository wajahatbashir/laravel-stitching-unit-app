<?php

namespace App\Models;

use App\Models\Concerns\LocksPeriod;
use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use LocksPeriod, Tracked;

    /** Records are frozen once the month of this column is closed (see PeriodLock). */
    public static string $lockColumn = 'date';

    public const TYPES = ['order', 'unit', 'labour', 'other'];

    public static bool $hasUuid = true;
    public static bool $ownScoped = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'due_date' => 'date', 'custom' => 'array', 'amount' => 'decimal:2', 'base_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        // deleting an expense also deletes its receipt and uploaded custom-field images
        static::deleted(function (self $e) {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            foreach (array_merge([$e->receipt_path], array_values((array) $e->custom)) as $p) {
                if (is_string($p) && str_starts_with($p, 'uploads/')) {
                    $disk->delete($p);
                }
            }
        });
    }

    public function category() { return $this->belongsTo(ExpenseCategory::class, 'category_id'); }
    public function order() { return $this->belongsTo(Order::class); }
    public function asset() { return $this->belongsTo(Asset::class); }
    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function currency() { return $this->belongsTo(Currency::class); }
}
