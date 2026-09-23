<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\StaffInviteService;
use App\Services\InviteDelivery;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password as PasswordRule;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Admin Accounts';
    protected static ?string $modelLabel = 'Admin Account';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('role', 'admin')->with('adminInvite');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')
                ->required()
                ->maxLength(255),
            TextInput::make('last_name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('phone')
                ->tel()
                ->maxLength(255),
            // No password field, on either create or edit. A new admin is sent
            // an invitation link and chooses their own; an existing one changes
            // it from their profile or through Forgot password. A password
            // typed here would be one the super admin also knows, which is
            // exactly what the invitation flow exists to avoid. The "Set
            // password manually" row action is the deliberate exception.
            Placeholder::make('password_note')
                ->label('Password')
                ->content(fn (string $operation) => $operation === 'create'
                    ? 'No password is set here. Saving emails this person a link to choose their own, valid for '
                        . StaffInviteService::EXPIRY_HOURS . ' hours.'
                    : 'Only this admin can change their own password. Use "Resend invite" or "Set password manually" if they are locked out.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->placeholder('—'),
                // Three states, not two. An account whose invitation was
                // never accepted is not archived and has no password anyone
                // has ever used - showing it as plain "Active" is how a super
                // admin ends up wondering why a colleague cannot sign in.
                TextColumn::make('is_archived')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state, User $record) => match (true) {
                        (bool) $state             => 'Inactive',
                        $record->hasPendingInvite() => 'Pending invite',
                        default                   => 'Active',
                    })
                    ->color(fn ($state, User $record) => match (true) {
                        (bool) $state             => 'gray',
                        $record->hasPendingInvite() => 'warning',
                        default                   => 'success',
                    }),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('is_archived')
                    ->label('Status')
                    ->options([
                        '0' => 'Active',
                        '1' => 'Inactive',
                    ]),

                SelectFilter::make('invite')
                    ->label('Invitation')
                    ->options([
                        'pending'  => 'Never accepted',
                        'accepted' => 'Accepted',
                    ])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'pending'  => $query->whereHas('adminInvite', fn ($q) => $q->unaccepted()),
                        'accepted' => $query->whereDoesntHave('adminInvite', fn ($q) => $q->unaccepted()),
                        default    => $query,
                    }),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),

                // Hidden once the account has been claimed: resending to a
                // working account would blank its invite back to "pending" and
                // make the panel lie about it. A locked-out admin who has
                // already accepted uses Forgot password, or the manual reset
                // below when mail is not reaching them.
                Action::make('resendInvite')
                    ->label('Resend invite')
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->visible(fn (User $record) => ! $record->is_archived && $record->hasPendingInvite())
                    ->requiresConfirmation()
                    ->modalHeading('Resend invitation')
                    ->modalDescription(fn (User $record) => 'A new link will be emailed to ' . $record->email
                        . '. Any earlier link stops working.')
                    ->action(function (User $record) {
                        static::inviteNotification(
                            $record,
                            StaffInviteService::send($record, auth()->id()),
                            'Invitation resent',
                        )->send();
                    }),

                // The escape hatch, and the only place a password is ever typed
                // for somebody else. It exists because MAIL_MAILER is still
                // `log` on this deployment: until a real transport is wired up,
                // no invitation actually reaches an inbox and an admin locked
                // out would otherwise have no way back in at all. Whoever uses
                // it should tell the admin to change it from their profile.
                Action::make('setPassword')
                    ->label('Set password manually')
                    ->icon('heroicon-o-key')
                    ->color('danger')
                    ->visible(fn (User $record) => ! $record->is_archived)
                    ->modalHeading(fn (User $record) => 'Set a password for ' . $record->name)
                    ->modalDescription('Use this only when email is not reaching them. You will know this password, so ask them to change it from their profile once they are in.')
                    ->modalSubmitActionLabel('Set password')
                    ->form([
                        TextInput::make('password')
                            ->label('New password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rule(PasswordRule::default())
                            ->same('password_confirmation'),
                        TextInput::make('password_confirmation')
                            ->label('Confirm password')
                            ->password()
                            ->revealable()
                            ->required(),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update(['password' => $data['password']]);

                        // The account is claimed now, however it happened. Left
                        // pending, it would keep reading "Pending invite" and
                        // stay excluded from User::activeAdmin().
                        $record->adminInvite?->update(['accepted_at' => now()]);

                        Notification::make()
                            ->title('Password set')
                            ->body('Ask ' . $record->first_name . ' to change it from their profile after signing in.')
                            ->success()
                            ->send();
                    }),

                Action::make('toggleArchive')
                    ->label(fn (User $record) => $record->is_archived ? 'Activate' : 'Deactivate')
                    ->icon(fn (User $record) => $record->is_archived ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol')
                    ->color(fn (User $record) => $record->is_archived ? 'success' : 'warning')
                    ->hidden(fn (User $record) => $record->id === auth()->id())
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => $record->is_archived ? 'Activate Account' : 'Deactivate Account')
                    ->modalDescription(fn (User $record) => $record->is_archived
                        ? 'This will restore the admin\'s access to the panel.'
                        : 'This will block the admin\'s access to the panel. You can reactivate anytime.')
                    ->action(fn (User $record) => $record->update(['is_archived' => !$record->is_archived])),
            ]);
    }

    /**
     * What the super admin is told after an invitation is issued — built in
     * one place so the create page and the Resend action cannot drift into
     * telling different stories about the same three outcomes.
     *
     * The rule: only say "sent" when something actually reached a person. A
     * green "invitation sent" over a mail that threw, or one handed to the log
     * driver, is how a super admin walks away believing a colleague has been
     * contacted while that colleague waits for an email nobody posted. In both
     * failure cases the link is printed, because it is then the only way left
     * to get them in.
     *
     * Bodies render as sanitized HTML in Filament, hence <strong> over markdown.
     */
    public static function inviteNotification(User $user, InviteDelivery $delivery, string $title): Notification
    {
        $expiry = StaffInviteService::EXPIRY_HOURS;

        if ($delivery->reachedSomeone()) {
            return Notification::make()
                ->title($title)
                ->body('An invitation was emailed to <strong>' . e($user->email) . '</strong>. '
                    . 'The link expires in ' . $expiry . ' hours.')
                ->success();
        }

        $reason = $delivery->mailConfigured
            ? 'The invitation email <strong>could not be sent</strong> (' . e($delivery->error ?? 'unknown error') . ').'
            : 'Email is not configured on this server, so <strong>nothing was sent</strong>.';

        return Notification::make()
            ->title($title)
            ->body($reason
                . ' The account and its invitation are saved, so nothing needs redoing — give '
                . e($user->first_name) . ' this link yourself, or use Resend invite once mail is working:'
                . '<br><strong>' . e($delivery->url) . '</strong>')
            ->warning()
            ->persistent();
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
