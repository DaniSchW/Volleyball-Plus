<?php

namespace App\Support\Activity;

use App\Models\ActivityLog;
use App\Support\Auth\CurrentMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records created / changed / deleted rows for the admins' activity log.
 * Models may define activityLabel() and $activityIgnore (attributes whose
 * change is bookkeeping, not a user's change).
 */
trait LogsActivity
{
    protected static function bootLogsActivity(): void
    {
        static::created(fn (Model $model) => self::logActivity($model, 'created'));
        static::updated(function (Model $model) {
            $ignore = array_merge(['updated_at', 'created_at'], property_exists($model, 'activityIgnore') ? $model->activityIgnore : []);
            if (array_diff(array_keys($model->getChanges()), $ignore) !== []) {
                self::logActivity($model, 'updated');
            }
        });
        static::deleted(fn (Model $model) => self::logActivity($model, 'deleted'));
    }

    private static function logActivity(Model $model, string $action): void
    {
        if (method_exists($model, 'shouldLogActivity') && ! $model->shouldLogActivity($action)) {
            return;
        }

        try {
            $current = app(CurrentMember::class);
            ActivityLog::create([
                'actor_id' => $current->has() ? $current->get()->id : null,
                'action' => $action,
                'subject_type' => Str::snake(class_basename($model)),
                'subject_id' => (string) $model->getKey(),
                'label' => Str::limit(method_exists($model, 'activityLabel') ? (string) $model->activityLabel() : (string) ($model->name ?? $model->title ?? $model->description ?? ''), 195),
            ]);
        } catch (Throwable) {
            // The log must never break the change itself.
        }
    }
}
