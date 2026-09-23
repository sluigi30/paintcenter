<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use Filament\Notifications\Notification;

/**
 * Same creation path as an admin account — random unusable password, emailed
 * invitation link, honest reporting of whether the mail actually went anywhere
 * — with `driver` as the role instead of `admin`.
 */
class CreateDriver extends CreateUser
{
    protected static string $resource = DriverResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return array_merge(parent::mutateFormDataBeforeCreate($data), [
            'role' => 'driver',
        ]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return UserResource::inviteNotification(
            $this->record,
            $this->delivery,
            'Driver account created',
        );
    }
}
