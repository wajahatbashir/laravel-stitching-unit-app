<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use Tracked;

    public const STATUSES = ['pending', 'in_progress', 'completed', 'delivered', 'cancelled'];

    public static bool $hasUuid = true;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2', 'rate' => 'decimal:2'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function expenses() { return $this->hasMany(Expense::class); }
    public function workEntries() { return $this->hasMany(WorkEntry::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function attachments() { return $this->morphMany(Attachment::class, 'attachable'); }

    public static function nextNumber(): string
    {
        $prefix = Setting::get('order_prefix', 'ORD-');
        $n = (int) static::withoutGlobalScopes()->max('id') + 1;
        do {
            $no = $prefix.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (static::withoutGlobalScopes()->where('order_no', $no)->exists());
        return $no;
    }

    public function materialCost(): float
    {
        return (float) $this->expenses()->withoutGlobalScopes()->sum('base_amount');
    }

    public function labourCost(): float
    {
        return (float) $this->workEntries()->withoutGlobalScopes()->sum('amount');
    }
}
