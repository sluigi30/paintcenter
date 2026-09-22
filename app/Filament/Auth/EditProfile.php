<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Where a signed-in admin changes their own password — the third and last way
 * a password gets set in this system, alongside the invitation link and the
 * forgot-password flow.
 *
 * Filament's stock page is almost right, but its form opens with a single
 * `name` field and this application has no such column: `users` stores
 * `first_name` / `last_name`, and `name` is an appended accessor over the two.
 * Left alone it renders a populated field that quietly saves nothing — `fill()`
 * drops it for not being fillable, so the admin edits their name, gets a
 * success notice, and nothing changes.
 *
 * Everything else is inherited, including the current-password challenge that
 * Filament already requires before a password or email change.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')
                ->label('First name')
                ->required()
                ->maxLength(255)
                ->autofocus(),
            TextInput::make('last_name')
                ->label('Last name')
                ->required()
                ->maxLength(255),
            TextInput::make('phone')
                ->label('Phone')
                ->tel()
                ->maxLength(255),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }
}
