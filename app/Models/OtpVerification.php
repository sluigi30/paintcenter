<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pending or completed phone verification. One row per phone (the newest
 * send replaces the old), keyed by the canonical 09XXXXXXXXX form so send,
 * verify and register all look it up the same way. Codes are never stored in
 * clear — only their hash — so a DB leak cannot reveal an in-flight code.
 */
class OtpVerification extends Model
{
    protected $fillable = [
        'phone',
        'code_hash',
        'expires_at',
        'verified_at',
        'attempts',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'verified_at' => 'datetime',
            'attempts'    => 'integer',
        ];
    }
}
