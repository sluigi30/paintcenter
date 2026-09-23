<?php

namespace App\Notifications;

use App\Services\StaffInviteService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your account exists — come and set a password on it."
 *
 * Carries NO password and no temporary credential. The only secret in this
 * mail is the one-time link, which dies the moment it is used or the window
 * closes. The email address is named because that is what they will type to
 * log in; that is access information, not a credential.
 */
class StaffAccountInvited extends Notification
{
    public function __construct(protected string $url) {}

    /** The panel this person belongs in — drivers and admins do not share one. */
    protected function panelLoginUrl(object $notifiable): string
    {
        $panel = \Filament\Facades\Filament::getPanel(
            $notifiable->isDriver() ? 'driver' : 'admin',
            isStrict: false,
        );

        return $panel?->getLoginUrl() ?? url('/admin/login');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // A driver being told they have an "administrator account" would
        // reasonably assume they had been sent somebody else's invitation.
        $kind = $notifiable->isDriver() ? 'delivery driver' : 'administrator';

        return (new MailMessage)
            ->subject('Your NCM Paint Center ' . ($notifiable->isDriver() ? 'driver' : 'admin') . ' account')
            ->greeting('Hello ' . $notifiable->first_name . ',')
            ->line('A ' . $kind . ' account has been created for you at NCM Paint Center.')
            ->line('You sign in with this email address: **' . $notifiable->email . '**')
            // WHERE to sign in, not just what to type. There are two panels
            // now and they refuse each other: a driver who goes to /admin is
            // told their password does not match, which reads exactly like a
            // password they mistyped when they set it.
            ->line('Your sign-in page is: ' . $this->panelLoginUrl($notifiable))
            ->line('For your security we have not set a password. Use the button below to choose one yourself.')
            ->action('Set your password', $this->url)
            ->line('This link can only be used once, and expires in ' . StaffInviteService::EXPIRY_HOURS . ' hours.')
            ->line('If the link has expired, ask the person who created your account to send a new invitation.')
            ->salutation('— NCM Paint Center');
    }
}
