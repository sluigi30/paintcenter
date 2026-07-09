<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryResource\Pages;
use App\Models\InventoryLog;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;


class InventoryResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationLabel = 'Inventory';
    protected static ?string $modelLabel = 'Inventory Item';
    protected static ?string $pluralModelLabel = 'Inventory';
    protected static ?int $navigationSort = 2;
    protected static \UnitEnum|string|null $navigationGroup = 'Operations';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-inbox-stack';
    // -------------------------------------------------------
    // Form — used when editing the low_stock_threshold
    // -------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('low_stock_threshold')
                ->label('Low Stock Alert Threshold')
                ->numeric()
                ->minValue(1)
                ->required()
                ->helperText('Admin will be alerted when stock drops to or below this number.'),
        ]);
    }

    // -------------------------------------------------------
    // Table — main inventory list
    // -------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                // Always show out-of-stock and low-stock products first
                Product::query()->orderByRaw("
                    CASE
                        WHEN stock = 0 THEN 0
                        WHEN stock <= low_stock_threshold THEN 1
                        ELSE 2
                    END ASC
                ")->orderBy('stock', 'asc')
            )
            ->columns([
            \Filament\Tables\Columns\ImageColumn::make('image')
                ->label('')
                ->circular()
                ->disk('public')
                ->defaultImageUrl(fn ($record) => 
                    'https://placehold.co/40x40/' . 
                    ltrim($record->hex_code ?? 'cccccc', '#') . 
                    '/' . 
                    ltrim($record->hex_code ?? 'cccccc', '#') . 
                    '?text=+'
                ),

                TextColumn::make('brand.brand_name')
                    ->label('Brand')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('description')
                    ->label('Product')
                    ->limit(40)
                    ->searchable()
                    ->tooltip(fn ($record) => $record->description),

                TextColumn::make('size_volume')
                    ->label('Size')
                    ->sortable(),

                TextColumn::make('stock')
                    ->label('Current Stock')
                    ->sortable()
                    ->alignCenter()
                    ->color(fn ($record) => match ($record->stock_status) {
                        'out_of_stock' => 'danger',
                        'low_stock'    => 'warning',
                        default        => 'success',
                    })
                    ->weight('bold'),

                TextColumn::make('low_stock_threshold')
                    ->label('Alert At')
                    ->alignCenter()
                    ->sortable()
                    ->color('gray'),

                TextColumn::make('stock_status')
                    ->label('Status')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'out_of_stock' => 'Out of Stock',
                        'low_stock'    => 'Low Stock',
                        default        => 'In Stock',
                    })
                    ->color(fn ($state) => match ($state) {
                        'out_of_stock' => 'danger',
                        'low_stock'    => 'warning',
                        default        => 'success',
                    }),

            ])

            // -------------------------------------------------------
            // Filters
            // -------------------------------------------------------
            ->filters([
                SelectFilter::make('stock_status')
                    ->label('Stock Status')
                    ->options([
                        // 'attention' is the landing target for the login
                        // stock alert's "Review Inventory" deep link
                        'attention'    => 'Needs Attention (Low + Out)',
                        'in_stock'     => 'In Stock',
                        'low_stock'    => 'Low Stock',
                        'out_of_stock' => 'Out of Stock',
                    ])
                    ->query(function (Builder $query, array $data) {
                        return match ($data['value'] ?? null) {
                            'attention'    => $query->lowStock(),
                            'low_stock'    => $query->lowStock()->where('stock', '>', 0),
                            'out_of_stock' => $query->outOfStock(),
                            'in_stock'     => $query->whereColumn('stock', '>', 'low_stock_threshold'),
                            default        => $query,
                        };
                    }),

                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'brand_name'),

                SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'category_name'),
            ])

            // -------------------------------------------------------
            // Row Actions
            // -------------------------------------------------------
            ->actions([

                // --- ADJUST STOCK ACTION ---
                Action::make('adjust_stock')
                    ->label('Adjust Stock')
                    ->icon('heroicon-o-arrows-up-down')
                    ->color('primary')
                    ->modalHeading(fn ($record) => 'Adjust Stock — ' . $record->description)
                    ->modalDescription(fn ($record) => 'Current stock: ' . $record->stock . ' units')
                    ->modalWidth('md')
                    ->form([
                        Select::make('action_type')
                            ->label('Action')
                            ->options([
                                'restock'    => '📦 Restock (Add stock)',
                                'deduct'     => '➖ Deduct (Remove stock)',
                                'adjustment' => '🔧 Manual Adjustment',
                            ])
                            ->required()
                            ->live(),

                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText(fn ($get) =>
                                $get('action_type') === 'deduct'
                                    ? 'This amount will be subtracted from current stock.'
                                    : 'This amount will be added to current stock.'
                            ),

                        Textarea::make('notes')
                            ->label('Reason / Notes')
                            ->placeholder('e.g. Supplier delivery, Damaged items removed, Stock count correction...')
                            ->rows(2)
                            ->maxLength(255),
                    ])
                    ->action(function (Product $record, array $data) {
                        $quantity = (int) $data['quantity'];
                        $action   = $data['action_type'];
                        $notes    = $data['notes'] ?? '';

                        // Deduct actions use a negative quantity
                        if ($action === 'deduct') {
                            // Prevent stock from going negative
                            if ($quantity > $record->stock) {
                                Notification::make()
                                    ->title('Insufficient Stock')
                                    ->body("Cannot deduct {$quantity} units. Only {$record->stock} units available.")
                                    ->danger()
                                    ->send();
                                return;
                            }
                            $quantity = -$quantity;
                        }

                        // Use the helper from InventoryLog (File 3)
                        InventoryLog::record($record, $action, $quantity, $notes);

                        // Show success notification
                        $direction = $quantity > 0 ? "Added +{$quantity}" : "Removed " . abs($quantity);
                        Notification::make()
                            ->title('Stock Updated')
                            ->body("{$direction} units. New stock: {$record->fresh()->stock}")
                            ->success()
                            ->send();
                    }),

                // --- VIEW LOGS ACTION ---
                Action::make('view_logs')
                    ->label('View Logs')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn ($record) => 'Inventory History — ' . $record->description)
                    ->modalContent(function (Product $record) {
                        $logs = $record->inventoryLogs()
                            ->with('admin')
                            ->latest()
                            ->take(20)
                            ->get();

                        return view('filament.inventory.logs-modal', compact('logs'));
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                // --- EDIT THRESHOLD ACTION ---
                Action::make('edit_threshold')
                    ->label('Set Alert')
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->modalHeading(fn ($record) => 'Set Low Stock Alert — ' . $record->description)
                    ->modalWidth('sm')
                    ->form([
                        TextInput::make('low_stock_threshold')
                            ->label('Alert me when stock drops to or below')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(fn (Product $record) => $record->low_stock_threshold)
                            ->suffix('units'),
                    ])
                    ->action(function (Product $record, array $data) {
                        $record->update([
                            'low_stock_threshold' => $data['low_stock_threshold'],
                        ]);

                        Notification::make()
                            ->title('Alert Threshold Updated')
                            ->body("You'll be alerted when stock drops to {$data['low_stock_threshold']} units.")
                            ->success()
                            ->send();
                    }),
            ])

            ->bulkActions([
                BulkActionGroup::make([]),
            ])

            ->emptyStateHeading('No products found')
            ->emptyStateDescription('Add products first from the Products section.')
            ->emptyStateIcon('heroicon-o-archive-box');
    }

    // -------------------------------------------------------
    // Pages
    // -------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventory::route('/'),
        ];
    }
}