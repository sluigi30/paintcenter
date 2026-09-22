<?php

namespace App\Services;

use App\Models\AdminInvite;
use App\Models\User;
use App\Notifications\AdminAccountInvited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one path by which an admin account becomes usable.
 *
 * A created admin has NO password anyone knows — not even the super admin who
 * created it. `issue()` mints a single-use link, the invited person sets their
 * own password through it, and `accept()` is the only thing that stamps the
 * account as claimed. That is deliberate: a password typed by one person and
 * emailed to another is a permanent plaintext copy sitting in an inbox, and it
 * means the super admin knows every admin's password, which empties the audit
 * trail of any meaning.
 *
 * The email carries a LINK, never a password.
 */
class AdminInviteService
{
    /**
     * How long a link stays good. Long, on purpose: an invite lands while
     * someone is off shift and a one-hour window just means every invite gets
     * resent. A password RESET is the short-lived one (60 minutes, Laravel's
     * default) because that flow starts with the person already at the keyboard.
     */
    public const EXPIRY_HOURS = 72;

    /**
     * Mint a link for this account, replacing any invite already outstanding.
     * Returns the PLAIN token — the only time it exists in readable form.
     */
    public static function issue(User $user, ?int $invitedById = null): string
    {
        $token = Str::random(64);

        AdminInvite::updateOrCreate(
            ['user_id' => $user->id],
            [
                'token_hash'  => hash('sha256', $token),
                'expires_at'  => now()->addHours(self::EXPIRY_HOURS),
                // A reissue re-opens the invite, so the panel's "Pending
                // invite" badge stays truthful about an account that once
                // again has no password its owner has chosen. The resend
                // action deliberately does NOT offer this for an account that
                // has already been claimed - that would lock a working admin
                // out until they clicked a link. Forgot password is their
                // route, and "Set password manually" is the one for when mail
                // is not reaching them at all.
                'accepted_at' => null,
                'invited_by'  => $invitedById,
            ]
        );

        return $token;
    }

    /**
     * Issue and email in one step.
     *
     * A failed send NEVER undoes the account or the invite. The two are
     * committed before the mail is attempted, so a transport that is down
     * costs a resend rather than making the super admin recreate the account
     * from scratch — and the invite stays pending, which is what keeps the
     * Resend action on screen.
     *
     * But the caller is told the truth about what happened. Reporting "sent!"
     * for a mail that threw, or for one handed to the log driver, leaves the
     * super admin believing a colleague has been contacted when nobody has;
     * they walk away and the invited person waits for an email that is never
     * coming. The returned InviteDelivery carries the link precisely because
     * in both bad cases it is the only way left to get that person in.
     */
    public static function send(User $user, ?int $invitedById = null): InviteDelivery
    {
        $url = self::url(self::issue($user, $invitedById));

        try {
            $user->notify(new AdminAccountInvited($url));
        } catch (\Throwable $e) {
            Log::warning('Admin invite email failed to send', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return InviteDelivery::failed($url, $e->getMessage());
        }

        // No exception, but the log mailer writes to a file and calls it a day.
        // Practically identical to a failure from the recipient's side, so it
        // must not be reported as a success.
        if (config('mail.default') === 'log') {
            return InviteDelivery::notMailed($url);
        }

        return InviteDelivery::sent($url);
    }

    public static function url(string $token): string
    {
        return route('filament.admin.invite.accept', ['token' => $token]);
    }

    /** The live invite for a token, or null if it is unknown, spent or stale. */
    public static function findPending(string $token): ?AdminInvite
    {
        $invite = AdminInvite::with('user')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $invite?->isPending() ? $invite : null;
    }

    /**
     * Claim the account: set the password and mark the invite accepted.
     *
     * Both writes in one transaction — an account with a password the person
     * chose but an invite still reading "pending" would keep showing up as an
     * unclaimed account and stay excluded from `User::activeAdmin()`.
     */
    public static function accept(AdminInvite $invite, string $password): User
    {
        return DB::transaction(function () use ($invite, $password) {
            $user = $invite->user;

            $user->update(['password' => $password]);

            $invite->update(['accepted_at' => now()]);

            return $user->refresh();
        });
    }
}
