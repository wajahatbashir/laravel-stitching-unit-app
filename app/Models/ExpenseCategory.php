<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An expense category; `types` lists the expense types (order / unit / labour / other) it can be used under. */
class ExpenseCategory extends Model
{
    public const TYPES = ['order', 'unit', 'labour', 'other'];

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'types' => 'array'];

    /** Types in the fixed order, falling back to the legacy single `type`. */
    public function typeList(): array
    {
        $t = $this->types ?: array_filter([$this->type]);

        return array_values(array_intersect(self::TYPES, $t));
    }
}
