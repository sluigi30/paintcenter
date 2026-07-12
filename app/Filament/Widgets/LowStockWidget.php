<?php

namespace App\Filament\Widgets;

use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;


class LowStockWidget extends BaseWidget
{
    // Sits below the stats overview on the dashboard
    protected static ?int $sort = 3;

    // Takes up the full dashboard width
    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = '⚠️ Low Stock & Out of Stock Alerts';

    // Only show this widget if any size is actually low on stock
    public static function canView(): bool
    {
        return ProductVariant::lowStock()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductVariant::query()
                    ->lowStock()
                    ->with(['product.brand', 'product.category'])
                    ->orderByRaw('stock ASC')             // worst stock levels first
            )
            ->columns([
                TextColumn::make('product.brand.brand_name')
                    ->label('Brand')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('product.description')
                    ->label('Product')
                    ->limit(45)
                    ->tooltip(fn ($record) => $record->product?->description)
                    ->searchable(),

                TextColumn::make('size_volume')
                    ->label('Size')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('stock')
                    ->label('Stock Left')
                    ->alignCenter()
                    ->weight('bold')
                    ->color(fn ($record) => $record->stock === 0 ? 'danger' : 'warning'),

                TextColumn::make('low_stock_threshold')
                    ->label('Alert At')
                    ->alignCenter()
                    ->color('gray'),

                TextColumn::make('stock_status')
                    ->label('Status')
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'out_of_stock' => '🔴  Out of Stock',
                        'low_stock'    => '🟡  Low Stock',
                        default        => '🟢  In Stock',
                    })
                    ->color(fn ($state) => match ($state) {
                        'out_of_stock' => 'danger',
                        'low_stock'    => 'warning',
                        default        => 'success',
                    }),

                TextColumn::make('product.category.category_name')
                    ->label('Category')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->actions([
                // Quick restock shortcut directly from the dashboard
                Action::make('quick_restock')
                    ->label('Restock')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->modalHeading(fn ($record) => 'Quick Restock — ' . $record->display_name)
                    ->modalDescription(fn ($record) => 'Current stock: ' . $record->stock . ' units')
                    ->modalWidth('sm')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('quantity')
                            ->label('Quantity to Add')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->placeholder('e.g. 50'),

                        \Filament\Forms\Components\Textarea::make('notes')
                            ->label('Reason (optional)')
                            ->placeholder('e.g. Supplier delivery Jan batch')
                            ->rows(2),
                    ])
                    ->action(function (ProductVariant $record, array $data) {
                        \App\Models\InventoryLog::record(
                            $record,
                            'restock',
                            (int) $data['quantity'],
                            $data['notes'] ?? ''
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Restocked Successfully')
                            ->body("Added {$data['quantity']} units. New stock: {$record->fresh()->stock}")
                            ->success()
                            ->send();
                    }),

                // Jump to full inventory page for that item
                Action::make('manage')
                    ->label('Manage')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record) => route('filament.admin.resources.inventories.index'))
                    ->openUrlInNewTab(false),
            ])

            ->emptyStateHeading('All items are well stocked!')
            ->emptyStateDescription('No sizes are currently low or out of stock.')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->paginated(false);          // show all alerts without pagination
    }
}
