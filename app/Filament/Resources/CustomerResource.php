<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Customers';
    protected static ?string $modelLabel = 'Customer';
    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        return ($user?->isAdmin() || $user?->isSuperAdmin()) ?? false;
    }

    // Customers self-register through the mobile/API; admins never create them here.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', 'customer')
            ->withCount('orders')
            ->withSum('orders', 'total_amount');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::query()
            ->where('role', 'customer')
            ->where('is_archived', false)
            ->count();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Customer')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name'])
                    ->weight('medium')
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('orders_sum_total_amount')
                    ->label('Total Spent')
                    ->money('PHP')
                    ->placeholder('₱0.00')
                    ->sortable(),
                TextColumn::make('is_archived')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Inactive' : 'Active')
                    ->color(fn ($state) => $state ? 'gray' : 'success'),
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('is_archived')
                    ->label('Status')
                    ->options([
                        '0' => 'Active',
                        '1' => 'Inactive',
                    ]),
                Filter::make('has_orders')
                    ->label('Has placed orders')
                    ->query(fn (Builder $query) => $query->has('orders')),
                Filter::make('registered')
                    ->schema([
                        DatePicker::make('from')->label('Registered from'),
                        DatePicker::make('until')->label('Registered until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'From ' . \Illuminate\Support\Carbon::parse($data['from'])->toFormattedDateString();
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Until ' . \Illuminate\Support\Carbon::parse($data['until'])->toFormattedDateString();
                        }
                        return $indicators;
                    }),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('toggleArchive')
                    ->label(fn (User $record) => $record->is_archived ? 'Activate' : 'Deactivate')
                    ->icon(fn (User $record) => $record->is_archived ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol')
                    ->color(fn (User $record) => $record->is_archived ? 'success' : 'warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => $record->is_archived ? 'Activate Customer' : 'Deactivate Customer')
                    ->modalDescription(fn (User $record) => $record->is_archived
                        ? 'This will restore the customer\'s account and app access.'
                        : 'This will block the customer from signing in to the app. You can reactivate anytime.')
                    ->action(fn (User $record) => $record->update(['is_archived' => !$record->is_archived])),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label('Full name'),
                    TextEntry::make('email')->label('Email')->copyable(),
                    TextEntry::make('phone')->label('Phone')->placeholder('—'),
                    TextEntry::make('address')->label('Address')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Activity')
                ->columns(3)
                ->schema([
                    TextEntry::make('orders_count')
                        ->label('Total orders')
                        ->state(fn (User $record) => $record->orders()->count()),
                    TextEntry::make('total_spent')
                        ->label('Total spent')
                        ->state(fn (User $record) => $record->orders()->sum('total_amount'))
                        ->money('PHP'),
                    TextEntry::make('created_at')
                        ->label('Registered')
                        ->dateTime('M j, Y g:i A'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
        ];
    }
}
