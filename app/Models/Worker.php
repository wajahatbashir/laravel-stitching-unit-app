<?php

namespace App\Models;

use App\Models\Concerns\Tracked;
use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    use Tracked;

    protected $guarded = [];
    protected $casts = ['joined_at' => 'date', 'is_active' => 'boolean', 'monthly_salary' => 'decimal:2'];

    public function user() { return $this->belongsTo(User::class); }
    public function rates() { return $this->hasMany(WorkerRate::class); }
    public function workEntries() { return $this->hasMany(WorkEntry::class)->withoutGlobalScopes(); }
    public function salaryEntries() { return $this->hasMany(SalaryEntry::class)->withoutGlobalScopes(); }
    public function payments() { return $this->hasMany(WorkerPayment::class)->withoutGlobalScopes(); }

    /** Rate for a garment: worker-specific override, else the default rate card. */
    public function rateFor(int $garmentTypeId): float
    {
        $r = $this->rates()->where('garment_type_id', $garmentTypeId)->value('rate');
        return (float) ($r ?? GarmentType::whereKey($garmentTypeId)->value('default_rate') ?? 0);
    }

    public function adjustments() { return $this->hasMany(WorkerAdjustment::class)->withoutGlobalScopes(); }

    /** Piece-rate + salary earnings, minus deductions (e.g. rejected pieces) plus bonuses. */
    public function earned(?string $from = null, ?string $to = null): float
    {
        $w = $this->workEntries();
        $s = $this->salaryEntries();
        $a = $this->adjustments();
        if ($from) { $w->whereDate('date', '>=', $from); $s->whereDate('period_month', '>=', $from); $a->whereDate('date', '>=', $from); }
        if ($to) { $w->whereDate('date', '<=', $to); $s->whereDate('period_month', '<=', $to); $a->whereDate('date', '<=', $to); }
        $adj = (float) $a->sum(\DB::raw("CASE WHEN type = 'deduction' THEN -amount ELSE amount END"));

        return (float) $w->sum('amount') + (float) $s->sum(\DB::raw('amount + bonus - deduction')) + $adj;
    }

    public function paid(?string $from = null, ?string $to = null): float
    {
        $p = $this->payments();
        if ($from) $p->whereDate('date', '>=', $from);
        if ($to) $p->whereDate('date', '<=', $to);
        return (float) $p->sum('amount');
    }

    /** >0 = we owe the worker; <0 = worker holds an advance that will be adjusted. */
    public function balance(): float
    {
        return round($this->earned() - $this->paid(), 2);
    }
}
