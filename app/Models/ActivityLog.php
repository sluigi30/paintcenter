<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single line in the admin audit trail.
 *
 * Every meaningful action an admin takes in the panel — creating a
 * product, editing a price, adjusting stock, cancelling an order —
 * lands here so a super admin can monitor the team's activity.
 *
 * Entries are written two ways:
 *   • automatically, by the TracksActivity trait on watched models
 *   • explicitly, via ActivityLog::log() (inventory moves, logins)
 */
class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event',
        'subject_type',
        'subject_id',
        'subject_label',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // -------------------------------------------------------
    // Writing entries
    // -------------------------------------------------------

    /**
     * Record an activity — but only when an admin or super admin is
     * the one acting. Customer API traffic and console/seeder writes
     * (no authenticated admin) are intentionally ignored, keeping the
     * feed a clean picture of staff activity.
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = []
    ): ?self {
        $admin = auth()->user();

        if (! $admin || ! ($admin->isAdmin() || $admin->isSuperAdmin())) {
            return null;
        }

        return static::create([
            'user_id'       => $admin->id,
            'event'         => $event,
            'subject_type'  => $subject ? $subject::class : null,
            'subject_id'    => $subject?->getKey(),
            'subject_label' => $subject ? static::labelFor($subject) : null,
            'description'   => $description,
            'properties'    => $properties ?: null,
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
        ]);
    }

    /**
     * A friendly name for the record being acted on, so the log stays
     * readable even after the subject itself is deleted.
     */
    public static function labelFor(Model $subject): string
    {
        if (method_exists($subject, 'activityTitle')) {
            return $subject->activityTitle();
        }

        return class_basename($subject) . ' #' . $subject->getKey();
    }

    // -------------------------------------------------------
    // Presentation accessors (used by the Filament resource)
    // -------------------------------------------------------

    public function getEventLabelAttribute(): string
    {
        return match ($this->event) {
            'created'   => 'Created',
            'updated'   => 'Updated',
            'deleted'   => 'Deleted',
            'inventory' => 'Inventory',
            'login'     => 'Signed in',
            default     => ucfirst($this->event),
        };
    }

    public function getEventColorAttribute(): string
    {
        return match ($this->event) {
            'created'   => 'success',
            'updated'   => 'info',
            'deleted'   => 'danger',
            'inventory' => 'warning',
            'login'     => 'gray',
            default     => 'gray',
        };
    }

    public function getEventIconAttribute(): string
    {
        return match ($this->event) {
            'created'   => 'heroicon-o-plus-circle',
            'updated'   => 'heroicon-o-pencil-square',
            'deleted'   => 'heroicon-o-trash',
            'inventory' => 'heroicon-o-cube',
            'login'     => 'heroicon-o-arrow-right-on-rectangle',
            default     => 'heroicon-o-bolt',
        };
    }

    /**
     * Short model name, e.g. "App\Models\ProductVariant" → "Product Variant".
     */
    public function getSubjectTypeLabelAttribute(): ?string
    {
        if (! $this->subject_type) {
            return null;
        }

        $base = class_basename($this->subject_type);

        return trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $base));
    }

    /**
     * One-line summary shown in the table when there is no custom
     * description, e.g. "Updated Product · Anzahl Urethane".
     */
    public function getSummaryAttribute(): string
    {
        if ($this->description) {
            return $this->description;
        }

        $parts = array_filter([$this->event_label, $this->subject_type_label]);
        $line  = implode(' ', $parts);

        return $this->subject_label
            ? "{$line} · {$this->subject_label}"
            : $line;
    }

    /**
     * Flattens the stored before/after diff into readable lines like
     * "price: 250 → 275" for display in the detail view.
     *
     * @return array<int, string>
     */
    public function getChangeLinesAttribute(): array
    {
        $changes = $this->properties['changes'] ?? null;

        if (! is_array($changes)) {
            return [];
        }

        $lines = [];

        foreach ($changes as $field => $diff) {
            $old = static::stringifyValue($diff['old'] ?? null);
            $new = static::stringifyValue($diff['new'] ?? null);
            $lines[] = ucwords(str_replace('_', ' ', $field)) . ": {$old} → {$new}";
        }

        return $lines;
    }

    protected static function stringifyValue($value): string
    {
        if (is_null($value) || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
