<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation to claim a newly created admin account.
 *
 * Deliberately NOT Laravel's `password_reset_tokens`, though the two look
 * alike. Three reasons, all of them things that bit when the shared version
 * was sketched out:
 *
 *  1. A panel has exactly ONE password broker. Filament's reset page hardcodes
 *     `Password::broker(Filament::getAuthPasswordBroker())`, so a second broker
 *     configured with a longer invite expiry would mint the token and then have
 *     it judged against the panel broker's expiry anyway. The longer window
 *     would be silently ignored.
 *  2. `password_reset_tokens` is keyed by email, one row, and the repository
 *     deletes any existing row on every `createToken`. An invited admin who
 *     clicks "Forgot password" before accepting destroys their own invite, and
 *     a resent invite destroys a reset already in flight.
 *  3. The broker knows nothing about account STATE. An invited-but-unclaimed
 *     admin is `role=admin, is_archived=false` and indistinguishable from a
 *     working account — it renders as "Active" and is eligible to be picked by
 *     `User::activeAdmin()` as the sender of every automated order message.
 *     `accepted_at` here is what makes that account knowable.
 *
 * So resets stay vanilla Laravel (60 minutes, email keyed) and invitations get
 * their own lifetime, their own state and their own audit of who issued them.
 *
 * One row per user: a resend overwrites the token and pushes the expiry out on
 * the same row, so there is never a question of which invite is live. The
 * token is stored as a plain sha256 rather than bcrypt because it is looked up
 * BY token — a 64-char random string needs no work factor, and bcrypt would
 * force a full table scan to find the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();

            // Who sent it. Nullable and null-on-delete: an invite outliving the
            // super admin who issued it is still a valid invite.
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invites');
    }
};
