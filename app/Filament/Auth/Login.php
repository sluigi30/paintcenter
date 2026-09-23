<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

/**
 * The panel login, with one thing added: it stops telling people their correct
 * password is wrong.
 *
 * Filament checks canAccessPanel() as part of the login attempt, and when that
 * fails it throws the SAME "These credentials do not match our records" it uses
 * for a bad password. With one panel that was fine — anyone refused there
 * genuinely had no business signing in. With two it is actively misleading: a
 * driver who types a perfectly good password at /admin/login is told it is
 * wrong, tries it again, assumes they mistyped when they set it, and asks for
 * the invitation to be resent. Nothing about the message hints that the only
 * problem is the door.
 *
 * Overriding the failure hook rather than authenticate() keeps this off the
 * successful path entirely: the extra credential check below only ever runs
 * when the login has ALREADY failed, so it costs nothing in the normal case and
 * cannot interfere with rate limiting or multi-factor.
 *
 * No enumeration risk: every branch here is reached only by someone who has
 * just proved they know the account's password.
 */
class Login extends BaseLogin
{
    protected function throwFailureValidationException(): never
    {
        file_put_contents(storage_path('logs/probe.txt'), 'reached'.PHP_EOL, FILE_APPEND);
        $user = $this->userWithCorrectPassword();
        file_put_contents(storage_path('logs/probe.txt'), 'user='.($user ? $user->role : 'null').PHP_EOL, FILE_APPEND);

        if ($user instanceof User) {
            file_put_contents(storage_path('logs/probe.txt'), 'panelUrl='.var_export($this->panelUrlFor($user), true).PHP_EOL, FILE_APPEND);
            if ($url = $this->panelUrlFor($user)) {
                throw ValidationException::withMessages([
                    'data.email' => 'That password is right, but this is the wrong sign-in page for your account. Sign in here instead: ' . $url,
                ]);
            }

            if ($user->isStaff() && $user->is_archived) {
                throw ValidationException::withMessages([
                    'data.email' => 'This account has been deactivated. Ask a super admin to reactivate it.',
                ]);
            }

            if ($user->role === 'customer') {
                throw ValidationException::withMessages([
                    'data.email' => 'This is a customer account. Shop and track orders in the NCM Paint Center app — the panels here are for staff.',
                ]);
            }
        }

        parent::throwFailureValidationException();
    }

    /**
     * The account these credentials belong to, or null if the password really
     * was wrong. Resolved through the guard's own provider so it stays in step
     * with however the panel is configured to authenticate.
     */
    protected function userWithCorrectPassword(): ?object
    {
        try {
            $credentials = $this->getCredentialsFromFormData($this->form->getState());
        } catch (\Throwable) {
            return null;
        }

        $provider = Filament::auth()->getProvider();
        $user     = $provider->retrieveByCredentials($credentials);

        if (! $user || ! $provider->validateCredentials($user, $credentials)) {
            return null;
        }

        return $user;
    }

    /** The panel this person CAN enter, if it is not the one they are at. */
    protected function panelUrlFor(User $user): ?string
    {
        $current = Filament::getCurrentOrDefaultPanel()?->getId();

        foreach (['admin', 'driver'] as $id) {
            if ($id === $current) {
                continue;
            }

            $panel = Filament::getPanel($id, isStrict: false);

            if ($panel && $user->canAccessPanel($panel)) {
                return $panel->getLoginUrl() ?? $panel->getUrl();
            }
        }

        return null;
    }
}
