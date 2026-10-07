<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Sets created_by + uuid, writes audit log, and (when $ownScoped) limits
 * users without the "data.all" permission to the rows they created.
 */
trait Tracked
{
    public static function bootTracked(): void
    {
        static::creating(function ($m) {
            if (Auth::check() && empty($m->created_by)) {
                $m->created_by = Auth::id();
            }
            if (property_exists(static::class, 'hasUuid') && static::$hasUuid) {
                $m->uuid = $m->uuid ?: (string) Str::uuid();
            }
        });

        foreach (['created', 'updated', 'deleted'] as $event) {
            static::$event(function ($m) use ($event) {
                $changes = $event === 'updated' ? $m->getChanges() : ($event === 'created' ? $m->getAttributes() : null);
                unset($changes['updated_at'], $changes['created_at']);
                if ($event === 'updated' && ! $changes) {
                    return;
                }
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => $event,
                    'model' => class_basename($m),
                    'model_id' => $m->getKey(),
                    'changes' => $changes,
                    'ip' => request()?->ip(),
                ]);
            });
        }

        if (property_exists(static::class, 'ownScoped') && static::$ownScoped) {
            static::addGlobalScope('own', function (Builder $q) {
                $u = Auth::user();
                if ($u && ! $u->can('data.all')) {
                    $q->where($q->getModel()->getTable().'.created_by', $u->id);
                }
            });
        }
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
