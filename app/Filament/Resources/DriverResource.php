<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages;
use Illuminate\Database\Eloquent\Builder;

/**
 * Driver accounts, managed exactly the way admin accounts are.
 *
 * Extends UserResource rather than copying it, because everything that matters
 * about creating a staff account is already solved there and solved carefully:
 * no password field, an invitation link instead, the three-valued status that
 * distinguishes "pending invite" from "active", and — the important one —
 * inviteNotification(), which refuses to claim an email was sent when it was
 * not. A second copy of that would drift, and the half that drifted would be
 * the half that lies about a mail nobody received.
 *
 * Only two things differ: which rows it shows, and what role a new one gets.
 */
class DriverResource extends UserResource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Drivers';

    protected static ?string $modelLabel = 'Driver';

    protected static ?string $slug = 'drivers';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        // Note this reaches past UserResource::getEloquentQuery(), which pins
        // role=admin. Calling parent here would return admins filtered to
        // drivers — that is, nothing at all.
        return static::getModel()::query()
            ->where('role', 'driver')
            ->with('adminInvite');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'edit'   => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
