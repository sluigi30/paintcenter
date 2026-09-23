<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use App\Models\User;
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

class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Activity Log';
    protected static ?string $modelLabel = 'Activity';
    protected static ?string $pluralModelLabel = 'Activity Log';
    protected static ?int $navigationSort = 9;

    /**
     * The audit trail is a super-admin oversight tool — regular admins
     * (the people being monitored) never see it.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Actions recorded today';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y g:i A')
                    ->description(fn (ActivityLog $record) => $record->created_at->diffForHumans())
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Admin')
                    ->weight('medium')
                    ->description(fn (ActivityLog $record) => $record->user?->role)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'user',
                        fn (Builder $q) => $q
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->placeholder('System'),
                TextColumn::make('event')
                    ->label('Action')
                    ->badge()
                    ->icon(fn (ActivityLog $record) => $record->event_icon)
                    ->color(fn (ActivityLog $record) => $record->event_color)
                    ->formatStateUsing(fn (ActivityLog $record) => $record->event_label),
                TextColumn::make('summary')
                    ->label('Activity')
                    ->wrap()
                    ->searchable(['subject_label', 'description'])
                    ->limit(90),
                TextColumn::make('subject_type_label')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Action')
                    ->options([
                        'created'   => 'Created',
                        'updated'   => 'Updated',
                        'deleted'   => 'Deleted',
                        'inventory' => 'Inventory',
                        'login'     => 'Signed in',
                    ]),
                // "Staff", not "Admin": drivers write to this feed too, and a
                // filter that cannot name them would hide the entries most
                // worth looking up — who took an order out and who delivered it.
                SelectFilter::make('user_id')
                    ->label('Staff')
                    ->options(fn () => User::query()
                        ->whereIn('role', ['admin', 'super_admin', 'driver'])
                        ->get()
                        ->pluck('name', 'id')
                        ->toArray())
                    ->searchable(),
                SelectFilter::make('subject_type')
                    ->label('Type')
                    ->options([
                        \App\Models\Product::class        => 'Product',
                        \App\Models\ProductVariant::class => 'Product Variant',
                        \App\Models\Order::class          => 'Order',
                        \App\Models\Brand::class          => 'Brand',
                        \App\Models\Category::class       => 'Category',
                        \App\Models\User::class           => 'User',
                    ]),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
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
            ])
            ->deferLoading();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Activity')
                ->columns(2)
                ->schema([
                    TextEntry::make('event')
                        ->label('Action')
                        ->badge()
                        ->icon(fn (ActivityLog $record) => $record->event_icon)
                        ->color(fn (ActivityLog $record) => $record->event_color)
                        ->formatStateUsing(fn (ActivityLog $record) => $record->event_label),
                    TextEntry::make('created_at')
                        ->label('When')
                        ->dateTime('M j, Y g:i A'),
                    TextEntry::make('user.name')
                        ->label('Performed by')
                        ->placeholder('System'),
                    TextEntry::make('user.role')
                        ->label('Role')
                        ->placeholder('—'),
                    TextEntry::make('summary')
                        ->label('Summary')
                        ->columnSpanFull(),
                ]),
            Section::make('What changed')
                ->visible(fn (ActivityLog $record) => ! empty($record->change_lines))
                ->schema([
                    TextEntry::make('change_lines')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->state(fn (ActivityLog $record) => $record->change_lines),
                ]),
            Section::make('Record')
                ->columns(2)
                ->schema([
                    TextEntry::make('subject_type_label')->label('Type')->placeholder('—'),
                    TextEntry::make('subject_label')->label('Name')->placeholder('—'),
                ]),
            Section::make('Origin')
                ->columns(2)
                ->collapsed()
                ->schema([
                    TextEntry::make('ip_address')->label('IP address')->placeholder('—'),
                    TextEntry::make('user_agent')->label('Device / browser')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }
}
