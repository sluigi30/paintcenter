<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TintColorResource\Pages;
use App\Models\TintColor;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The colorant presets customers add to a mixing base, by the ml.
 *
 * tint_strength is deliberately absent from the form. It is Kubelka-Munk
 * calibration: changing it moves every preview the app draws, and an admin
 * nudging it up because "stronger" sounds better would silently make every
 * future recipe wrong. It is set by calibrating against a real mixed can.
 */
class TintColorResource extends Resource
{
    protected static ?string $model = TintColor::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|\UnitEnum|null $navigationGroup = 'Store Management';

    protected static ?string $navigationLabel = 'Tint Colors';

    protected static ?string $modelLabel = 'tint color';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    /** What a customer's +/- button moves by. Tenths only; see TintColor::acceptsAmount(). */
    public const STEP_OPTIONS = [
        '0.5' => '0.5 ml — very strong (black)',
        '1'   => '1 ml — strong',
        '2'   => '2 ml',
        '5'   => '5 ml — weak (yellows, whites)',
        '10'  => '10 ml',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Name')
                ->placeholder('e.g. Oxide Red')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(60),

            Select::make('step_ml')
                ->label('Step')
                ->options(self::STEP_OPTIONS)
                ->default('1')
                ->required()
                ->helperText('The smallest amount a customer can add. Strong colorants need fine steps — 5 ml of black already darkens a whole litre.'),

            ColorPicker::make('hex_code')
                ->label('Colour')
                ->required()
                ->regex('/^#[0-9A-Fa-f]{6}$/')
                ->helperText('The colorant\'s own colour, used for the app\'s preview. Approximate — a screen cannot show pigment exactly.'),

            ViewField::make('hex_picker')
                ->view('filament.forms.color-from-image')
                ->hiddenLabel()
                ->dehydrated(false)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                ColorColumn::make('hex_code')
                    ->label('Colour'),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (TintColor $record) => $record->hex_code),
                TextColumn::make('step_ml')
                    ->label('Step')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 1), '0'), '.').' ml'),
                TextColumn::make('is_archived')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Unavailable' : 'Available')
                    ->color(fn ($state) => $state ? 'gray' : 'success'),
            ])
            ->filters([
                SelectFilter::make('is_archived')
                    ->label('Status')
                    ->options([
                        '0' => 'Available only',
                        '1' => 'Unavailable only',
                    ])
                    ->default('0'),
            ])
            ->actions([
                EditAction::make(),
                // Out of colorant = unavailable. Never deleted: placed orders
                // name it, and it comes back when the counter restocks.
                Action::make('toggleArchive')
                    ->label(fn (TintColor $record) => $record->is_archived ? 'Make available' : 'Mark unavailable')
                    ->icon(fn (TintColor $record) => $record->is_archived ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-archive-box')
                    ->color(fn (TintColor $record) => $record->is_archived ? 'success' : 'warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (TintColor $record) => $record->is_archived
                        ? 'Customers will be able to add this colour to their mixes again.'
                        : 'Customers will no longer be offered this colour. Carts that already contain it will be asked to edit their mix before checking out.')
                    ->action(fn (TintColor $record) => $record->update(['is_archived' => ! $record->is_archived])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTintColors::route('/'),
            'create' => Pages\CreateTintColor::route('/create'),
            'edit'   => Pages\EditTintColor::route('/{record}/edit'),
        ];
    }
}
