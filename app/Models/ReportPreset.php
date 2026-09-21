<?php

namespace App\Models;

use App\Services\Reports\PresetPayload;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved report configuration, belonging to one admin.
 *
 * Persistence only — the payload's shape and the rules for reading it back
 * live in PresetPayload.
 */
class ReportPreset extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Presets an admin may open. Scoped, so one admin cannot load another's. */
    public function scopeOwnedBy($query, User $user)
    {
        return $query->where('user_id', $user->id)->orderBy('name');
    }

    /** Human description of the saved range, for the presets list. */
    public function getRangeLabelAttribute(): string
    {
        return PresetPayload::rangeLabel($this->payload ?? []);
    }

    public function getSectionCountAttribute(): int
    {
        return count($this->payload['sections'] ?? []);
    }
}
