<?php

namespace App\Notifications;

use App\Services\AdminInviteService;
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
class AdminAccountInvited extends Notification
{
    public function __construct(protected string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your NCM Paint Center admin account')
            ->greeting('Hello ' . $notifiable->first_name . ',')
            ->line('An administrator account has been created for you at NCM Paint Center.')
            ->line('You sign in with this email address: **' . $notifiable->email . '**')
            ->line('For your security we have not set a password. Use the button below to choose one yourself.')
            ->action('Set your password', $this->url)
            ->line('This link can only be used once, and expires in ' . AdminInviteService::EXPIRY_HOURS . ' hours.')
            ->line('If the link has expired, ask the person who created your account to send a new invitation.')
            ->salutation('— NCM Paint Center');
    }
}
