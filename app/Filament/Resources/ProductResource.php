<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';
    protected static string|\UnitEnum|null $navigationGroup = 'Store Management';
    protected static ?int $navigationSort = 1;

    /**
     * Which base a can holds, for custom-colour products. '' means the paint
     * line makes no base distinction. The app derives the required base from
     * the customer's colour and matches it against this — see ColorService.
     */
    public const BASE_LABELS = [
        ''  => 'Not a base',
        'P' => 'Pastel base — light, muted colours',
        'M' => 'Medium base — mid tones',
        'D' => 'Deep base — dark or saturated colours',
    ];

    /** Short forms, for the repeater row headings. */
    public const BASE_SHORT = ['' => '', 'P' => 'Pastel', 'M' => 'Medium', 'D' => 'Deep'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')
                ->relationship('brand', 'brand_name')
                ->required()
                ->searchable()
                ->preload()
                ->label('Brand'),

            Select::make('category_id')
                ->relationship('category', 'category_name')
                ->required()
                ->searchable()
                ->preload()
                ->label('Category'),

            Textarea::make('description')
                ->columnSpanFull()
                ->label('Description'),

            // A custom-colour product is a real SKU: its variants are cans of
            // untinted BASE on the shelf, and the colour is chosen by the
            // customer at order time. See CUSTOM_COLOR.md.
            Toggle::make('is_custom_color')
                ->label('Customer chooses the colour')
                ->helperText('For untinted base paint mixed to order. The colour fields below do not apply — each order carries its own colour.')
                ->live()
                ->columnSpanFull(),

            TextInput::make('color_code')
                ->label('Color Code')
                ->placeholder('e.g. 888')
                ->maxLength(40)
                ->helperText('The manufacturer\'s code on the can / shade card.')
                ->hidden(fn ($get) => $get('is_custom_color')),

            TextInput::make('color_name')
                ->label('Color Name')
                ->placeholder('e.g. Red')
                ->maxLength(100)
                ->hidden(fn ($get) => $get('is_custom_color')),

            ColorPicker::make('hex_code')
                ->label('Screen Preview Color')
                ->helperText('Tip: open the brand\'s color chart and use the picker\'s eyedropper to sample the swatch.')
                ->hidden(fn ($get) => $get('is_custom_color')),

            FileUpload::make('images')
                ->label('Product Images')
                ->image()
                ->multiple()
                ->reorderable()
                ->maxFiles(8)
                ->directory('products')
                ->helperText('Up to 8 images. Drag to reorder — the first image is the cover shown in lists and the cart.')
                ->columnSpanFull(),

            // Each size/volume is its own variant with its own price and
            // stock. Stock is set here only on creation — afterwards all
            // stock movements go through Inventory (audit-trailed).
            Repeater::make('variants')
                ->relationship()
                ->label('Sizes / Volumes')
                ->columnSpanFull()
                ->columns(4)
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel('Add size')
                ->itemLabel(fn (array $state) => trim(
                    ($state['size_volume'] ?? '') .
                    (($state['base_code'] ?? '') !== ''
                        ? ' · ' . (self::BASE_SHORT[$state['base_code']] ?? $state['base_code'])
                        : '')
                ) ?: null)
                // A size may now legitimately appear once PER BASE — 4L pastel
                // and 4L deep are different cans. So uniqueness is on the pair,
                // which ->distinct() on size_volume alone cannot express: it
                // would reject the second row outright.
                ->rules([
                    fn () => function (string $attribute, $value, \Closure $fail) {
                        $seen = [];

                        foreach ((array) $value as $row) {
                            $key = ($row['size_volume'] ?? '') . '|' . ($row['base_code'] ?? '');

                            if (isset($seen[$key])) {
                                $fail('Each size can only be listed once per base.');
                                return;
                            }

                            $seen[$key] = true;
                        }
                    },
                ])
                ->schema([
                    TextInput::make('size_volume')
                        ->label('Size / Volume')
                        ->placeholder('e.g. 4L')
                        ->required()
                        ->maxLength(30),

                    // Which base this can holds. Light colours need plenty of
                    // white, dark saturated ones need almost none — the app
                    // works this out from the customer's colour and matches it
                    // against this field, so it must be right.
                    Select::make('base_code')
                        ->label('Base')
                        ->options(self::BASE_LABELS)
                        ->default('')
                        ->required()
                        ->visible(fn ($get) => $get('../../is_custom_color'))
                        ->helperText('Leave as "Not a base" if this paint line has only one base.'),

                    TextInput::make('price')
                        ->label('Price')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₱'),

                    TextInput::make('tint_fee')
                        ->label('Tint Fee')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('₱')
                        ->visible(fn ($get) => $get('../../is_custom_color'))
                        ->helperText('Charged on top of the price when this can is mixed.'),

                    TextInput::make('stock')
                        ->label('Initial Stock')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->disabledOn('edit')
                        ->helperText('After creation, adjust via Inventory.'),

                    TextInput::make('low_stock_threshold')
                        ->label('Alert At')
                        ->numeric()
                        ->minValue(1)
                        ->default(10)
                        ->required(),
                ]),
        ]);
    }
    //test comment
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Image')
                    ->circular(),
                TextColumn::make('brand.brand_name')
                    ->label('Brand')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.category_name')
                    ->label('Category')
                    ->searchable()
                    ->sortable(),
                // A custom-colour product has no colour of its own; the badge
                // beside this says so, so an empty swatch is not read as
                // "not filled in yet".
                ColorColumn::make('hex_code')
                    ->label('Color')
                    ->placeholder('—'),
                TextColumn::make('color_code')
                    ->label('Code')
                    ->badge()
                    ->color(fn (Product $record) => $record->is_custom_color ? 'info' : 'gray')
                    ->state(fn (Product $record) => $record->is_custom_color
                        ? 'Custom colour'
                        : $record->color_code)
                    ->placeholder('—')
                    ->tooltip(fn (Product $record) => $record->is_custom_color
                        ? 'Mixed to the customer\'s chosen colour'
                        : $record->color_name)
                    ->searchable(),
                // One badge per size, e.g. [1L] [4L] [16L]
                TextColumn::make('variants.size_volume')
                    ->label('Sizes')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('price')
                    ->label('Price')
                    ->state(fn (Product $record) => $record->price)
                    ->formatStateUsing(function (Product $record) {
                        $prices = $record->variants->where('is_archived', false)->pluck('price');
                        if ($prices->isEmpty()) {
                            return '—';
                        }
                        $min = number_format($prices->min(), 2);
                        $max = number_format($prices->max(), 2);
                        return $min === $max ? "₱{$min}" : "₱{$min} – ₱{$max}";
                    }),
                TextColumn::make('stock')
                    ->label('Total Stock')
                    ->state(fn (Product $record) => $record->stock)
                    ->color(fn (Product $record) => match ($record->stock_status) {
                        'out_of_stock' => 'danger',
                        'low_stock'    => 'warning',
                        default        => 'success',
                    }),
                // Archive status badge
                TextColumn::make('is_archived')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Archived' : 'Active')
                    ->color(fn ($state) => $state ? 'gray' : 'success'),
            ])
            ->filters([
                SelectFilter::make('stock_status')
                    ->label('Stock Level')
                    ->options([
                        'attention'    => 'Needs Attention (Low + Out)',
                        'out_of_stock' => 'Has Out-of-Stock Size',
                        'low_stock'    => 'Has Low-Stock Size',
                        'in_stock'     => 'Healthy',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'attention'    => $query->lowStock(),
                        'out_of_stock' => $query->outOfStock(),
                        'low_stock'    => $query->whereHas('variants', fn ($q) => $q
                            ->where('is_archived', false)
                            ->where('stock', '>', 0)
                            ->whereColumn('stock', '<=', 'low_stock_threshold')),
                        'in_stock'     => $query->whereDoesntHave('variants', fn ($q) => $q
                            ->where('is_archived', false)
                            ->whereColumn('stock', '<=', 'low_stock_threshold')),
                        default        => $query,
                    }),

                SelectFilter::make('brand')
                    ->relationship('brand', 'brand_name'),
                SelectFilter::make('category')
                    ->relationship('category', 'category_name'),
                // Filter: show active, archived, or all
                SelectFilter::make('is_archived')
                    ->label('Status')
                    ->options([
                        '0' => 'Active only',
                        '1' => 'Archived only',
                    ])
                    ->default('0'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                // Archive / Unarchive toggle — NO delete
                Action::make('toggleArchive')
                    ->label(fn (Product $record) => $record->is_archived ? 'Unarchive' : 'Archive')
                    ->icon(fn (Product $record) => $record->is_archived ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-archive-box')
                    ->color(fn (Product $record) => $record->is_archived ? 'success' : 'warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Product $record) => $record->is_archived ? 'Unarchive Product' : 'Archive Product')
                    ->modalDescription(fn (Product $record) => $record->is_archived
                        ? 'This will make the product visible to customers again.'
                        : 'This will hide the product from customers. You can unarchive it anytime.')
                    ->action(fn (Product $record) => $record->update(['is_archived' => !$record->is_archived])),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('archiveSelected')
                        ->label('Archive selected')
                        ->icon('heroicon-o-archive-box')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['is_archived' => true])),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        // The Sizes / Price / Total Stock columns all read from variants
        return parent::getEloquentQuery()->with('variants');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}