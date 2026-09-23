<?php

namespace App\Filament\Auth;

use App\Models\AdminInvite;
use App\Models\User;
use App\Services\StaffInviteService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Locked;

/**
 * Where an invited admin chooses their own password, reached only by the link
 * in their invitation email.
 *
 * Lives outside `app/Filament/Pages` on purpose: that directory is scanned by
 * the panel's page discovery, and a discovered page gets a navigation entry
 * and an authenticated route. This one is registered by hand in
 * AdminPanelProvider under `routes()` — the GUEST route group — because the
 * whole point is that the person visiting has no account they can log into yet.
 */
class AcceptInvite extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public ?string $token = null;

    /**
     * Set when the link is unknown, already used, expired, or belongs to an
     * account that has since been deactivated. One flag for all four: telling
     * a visitor which of those it was tells them something about an account
     * they have just proved they do not control.
     */
    #[Locked]
    public bool $invalid = false;

    #[Locked]
    public ?string $email = null;

    public ?string $password = '';

    public ?string $passwordConfirmation = '';

    protected ?AdminInvite $invite = null;

    public function mount(string $token): void
    {
        if (Filament::auth()->check()) {
            Filament::auth()->logout();
        }

        $this->token = $token;

        $invite = StaffInviteService::findPending($token);

        // An invite for an account that has been deactivated since it was sent
        // is dead. Without this the person sets a password, is bounced by
        // canAccessPanel(), and has no idea why.
        if (! $invite || $invite->user === null || $invite->user->is_archived) {
            $this->invalid = true;

            return;
        }

        $this->invite = $invite;
        $this->email  = $invite->user->email;
    }

    public function acceptInvite(): mixed
    {
        if ($this->invalid) {
            return null;
        }

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title('Too many attempts')
                ->body("Please wait {$exception->secondsUntilAvailable} seconds and try again.")
                ->danger()
                ->send();

            return null;
        }

        $data = $this->form->getState();

        // Re-resolved rather than trusted from mount(): the page may have sat
        // open for hours, and the invite can have been resent (new token) or
        // the account deactivated in the meantime.
        $invite = StaffInviteService::findPending($this->token);

        if (! $invite || $invite->user === null || $invite->user->is_archived) {
            $this->invalid = true;

            Notification::make()
                ->title('This invitation is no longer valid')
                ->danger()
                ->send();

            return null;
        }

        $user = StaffInviteService::accept($invite, $data['password']);

        Filament::auth()->login($user);
        session()->regenerate();

        Notification::make()
            ->title('Welcome to NCM Paint Center')
            ->body('Your password is set and you are signed in.')
            ->success()
            ->send();

        // NOT Filament::getUrl(). This page is registered on the admin panel's
        // guest routes, so that resolves to /admin for everyone who lands here
        // — including a driver, who would be signed in successfully and then
        // bounced straight back out by canAccessPanel(). Send each person to
        // the panel their role can actually enter.
        //
        // intended() is dropped for the same reason: whatever they were trying
        // to reach before setting a password was, by definition, a page they
        // were not signed in for, and for a driver it is very likely a page in
        // a panel they will never be allowed into.
        return redirect(static::homeFor($user));
    }

    /** The panel this account belongs in, once it exists. */
    protected static function homeFor(User $user): string
    {
        return $user->isDriver()
            ? Filament::getPanel('driver')->getUrl()
            : Filament::getPanel('admin')->getUrl();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->label('Email address')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('password')
                ->label('Choose a password')
                ->password()
                ->autocomplete('new-password')
                ->autofocus()
                ->revealable(Filament::arePasswordsRevealable())
                ->required()
                ->rule(PasswordRule::default())
                ->same('passwordConfirmation'),
            TextInput::make('passwordConfirmation')
                ->label('Confirm password')
                ->password()
                ->autocomplete('new-password')
                ->revealable(Filament::arePasswordsRevealable())
                ->required()
                ->dehydrated(false),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        if ($this->invalid) {
            return $schema->components([
                Text::make(
                    'This invitation link is no longer valid. It may have already been used, ' .
                    'or it may have expired. Ask whoever set up your account to send a new one.'
                ),
                Actions::make([
                    Action::make('backToLogin')
                        ->label('Back to sign in')
                        ->url(Filament::getLoginUrl())
                        ->link(),
                ]),
            ]);
        }

        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('acceptInvite')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->fullWidth()
                        ->key('form-actions'),
                ]),
        ]);
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('acceptInvite')
                ->label('Set password and sign in')
                ->submit('acceptInvite'),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Set your password';
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->invalid ? 'Invitation expired' : 'Set your password';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->invalid ? null : 'Choose a password for ' . $this->email;
    }

    protected function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')]);
    }
}
