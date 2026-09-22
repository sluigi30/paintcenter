<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\AdminInviteService;
use App\Services\InviteDelivery;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** What actually happened to the invitation email, reported by the toast. */
    protected ?InviteDelivery $delivery = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['role'] = 'admin';

        // The form no longer collects one, and the column is NOT NULL. A long
        // random string nobody has ever seen is the point: until the invitation
        // is accepted there is no password that works, and the account cannot
        // be signed into even by whoever created it.
        $data['password'] = Str::random(64);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->delivery = AdminInviteService::send($this->record, auth()->id());
    }

    /**
     * The account is created either way — a mail failure must not cost the
     * super admin the record they just filled in. But it is not reported as a
     * success unless the email genuinely went somewhere; see
     * UserResource::inviteNotification().
     */
    protected function getCreatedNotification(): ?Notification
    {
        return UserResource::inviteNotification(
            $this->record,
            $this->delivery,
            'Admin account created',
        );
    }
}
