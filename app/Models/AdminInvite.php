<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending or accepted invitation to claim an admin account. One row per
 * user — a resend rewrites this row rather than adding another, so "the
 * invite" is never ambiguous.
 *
 * Tokens are never stored in clear. The link carries the only copy; losing it
 * means a resend, which is the intended recovery.
 */
class AdminInvite extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'accepted_at',
        'invited_by',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Issued, still in date, not yet claimed — the only state a link works in. */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** Issued, never claimed, and the window has closed. Needs a resend. */
    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /** Accounts whose invite was never claimed — not yet real admins. */
    public function scopeUnaccepted($query)
    {
        return $query->whereNull('accepted_at');
    }
}
