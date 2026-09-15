<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InventoryResource;
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
            // Table widgets do not inherit the stats widget's polling, so this
            // sat frozen while customers bought the very stock it lists.
            ->poll('30s')
            ->query(
                ProductVariant::query()
                    ->lowStock()
                    ->with(['product.brand', 'product.categories'])
                    ->orderByRaw('stock ASC')             // worst stock levels first
            )
            ->columns([
                TextColumn::make('product.brand.brand_name')
                    ->label('Brand')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->limit(45)
                    ->tooltip(fn ($record) => $record->product?->name)
                    ->searchable(),

                // Two low rows of the same product and size are different
                // shades — without this they read as a duplicate.
                TextColumn::make('color_label')
                    ->label('Color')
                    ->badge()
                    ->color('gray')
                    ->state(fn ($record) => $record->color_label ?: null)
                    ->placeholder('-'),

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

                TextColumn::make('product.categories.category_name')
                    ->label('Category')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->actions([
                // Jump to Inventory with the product already searched, so the
                // admin lands on its rows instead of the full list with the
                // item they just clicked to find nowhere in sight. `search` is
                // Filament's URL alias for tableSearch — the same handoff the
                // stock-alert notifications use.
                //
                // By product name, not the exact can: `size_volume` is not a
                // searchable column, so adding the size would AND in a term
                // that matches nothing and return an empty table.
                Action::make('manage')
                    ->label('Manage')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (ProductVariant $record) => InventoryResource::getUrl('index', [
                        'search' => $record->product?->name,
                    ]))
                    ->openUrlInNewTab(false),
            ])

            ->emptyStateHeading('All items are well stocked!')
            ->emptyStateDescription('No sizes are currently low or out of stock.')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->paginated(false);          // show all alerts without pagination
    }
}
