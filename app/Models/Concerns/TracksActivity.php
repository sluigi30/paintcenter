<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;

/**
 * Drop this trait on any model to have its create / update / delete
 * events recorded in the admin audit trail — but only when an admin
 * is the one making the change (see ActivityLog::log).
 *
 * Models may customise behaviour with two optional methods:
 *   activityTitle(): string           — friendly label for the record
 *   activityIgnored(): array<string>  — attributes to leave out of the diff
 */
trait TracksActivity
{
    public static function bootTracksActivity(): void
    {
        static::created(fn ($model) => $model->recordActivity('created'));
        static::updated(fn ($model) => $model->recordActivity('updated'));
        static::deleted(fn ($model) => $model->recordActivity('deleted'));
    }

    protected function recordActivity(string $event): void
    {
        $properties = [];

        if ($event === 'updated') {
            $changes = $this->trackedChanges();

            // Nothing worth logging changed (e.g. only stock, which is
            // audited separately by InventoryLog) — skip the noise.
            if (empty($changes)) {
                return;
            }

            $properties['changes'] = $changes;
        }

        ActivityLog::log($event, $this, null, $properties);
    }

    /**
     * Builds an old → new diff of the attributes that actually changed,
     * excluding timestamps, secrets, and any model-specific noise.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function trackedChanges(): array
    {
        $ignored = array_merge(
            ['created_at', 'updated_at', 'password', 'remember_token'],
            method_exists($this, 'activityIgnored') ? $this->activityIgnored() : []
        );

        $changes = [];

        foreach ($this->getChanges() as $key => $new) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            $changes[$key] = [
                'old' => $this->getOriginal($key),
                'new' => $new,
            ];
        }

        return $changes;
    }
}
