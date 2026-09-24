<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static string|\UnitEnum|null $navigationGroup = 'Store Management';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')
                ->relationship('brand', 'brand_name')
                ->required()
                ->searchable()
                ->preload()
                ->label('Brand'),

            TextInput::make('name')
                ->label('Product Name')
                ->placeholder('e.g. BOYSEN Latex Colors')
                ->helperText('The paint line, without the shade — the colours go in the list below.')
                ->required()
                ->maxLength(255),

            // A paint is often shelved under more than one heading: an enamel
            // that is also a wood coating belongs in both, or customers
            // browsing either one never find it.
            Select::make('categories')
                ->relationship('categories', 'category_name')
                ->multiple()
                ->required()
                ->searchable()
                ->preload()
                ->label('Categories')
                ->helperText('Pick every category this product belongs in.')
                ->columnSpanFull(),

            Textarea::make('description')
                ->columnSpanFull()
                ->label('Description')
                ->helperText('What the paint is for — surfaces, finish, coverage. Not the name.'),

            // Base cans for the mixing bench. They are stocked and priced like
            // any paint, but never listed in the catalogue — a customer buys
            // one only with a tint recipe in it. See MIXING.md.
            Toggle::make('is_mixing_base')
                ->label('Mixing base (hidden from the catalogue)')
                ->helperText('Sold only on the app\'s mixing screen, with the customer\'s colorant added. Each row picks its base type (White, Pastel, Deep…), which sets the preview colour and how strongly colour shows in it.')
                ->live()
                ->columnSpanFull(),

            FileUpload::make('images')
                ->label('Product Images')
                ->image()
                ->multiple()
                ->reorderable()
                ->maxFiles(8)
                // Pinned, not left to the default disk. The environment
                // default is the PRIVATE message-attachment bucket, and a
                // catalogue image does not belong in it — these are fetched by
                // URL with no auth, and R2 rejects the visibility calls a
                // public write to a private bucket produces anyway.
                ->disk('public')
                ->directory('products')
                ->helperText('Up to 8 images. Drag to reorder — the first image is the cover shown in lists and the cart.')
                ->columnSpanFull(),

            // One row per CAN on the shelf: a colour, in a size (on a mixing
            // base, the colour name says which base). Price and stock sit here because
            // that is where they actually differ. Stock is set here only on
            // creation — afterwards every movement goes through Inventory,
            // which audit-trails it.
            Repeater::make('variants')
                ->relationship()
                ->label('Colors & Sizes')
                ->helperText('One row per colour and size. Use a row\'s copy button to add another size of the same colour.')
                ->columnSpanFull()
                ->columns(4)
                ->minItems(1)
                ->defaultItems(1)
                ->collapsible()
                ->cloneable()
                // Persisted, because nothing else decides the order shades and
                // sizes are shown in — see Product::variants().
                ->reorderable()
                ->orderColumn('sort_order')
                ->addActionLabel('Add color / size')
                ->itemLabel(function (array $state) {
                    $code = trim((string) ($state['color_code'] ?? ''));
                    $name = ! empty($state['base_type'])
                        ? (\App\Models\ProductVariant::baseTypeOptions()[$state['base_type']] ?? '')
                        : trim((string) ($state['color_name'] ?? ''));
                    $color = $name !== '' && $code !== '' ? "{$name} ({$code})" : ($name !== '' ? $name : $code);

                    return trim(
                        ($color !== '' ? $color.' · ' : '').($state['size_volume'] ?? '')
                    ) ?: null;
                })
                // A size may legitimately appear once PER COLOUR — 4L Burnt
                // Sienna, 4L White and a 4L Pastel Base are different cans. So
                // uniqueness is on the whole combination, which ->distinct() on
                // a single column cannot express: it would reject the second
                // row outright.
                ->rules([
                    fn () => function (string $attribute, $value, \Closure $fail) {
                        $seen = [];

                        foreach ((array) $value as $row) {
                            // A typed base is identified by its type — its
                            // name is set from it on save, so the name field
                            // is not what the admin chose.
                            $key = implode('|', [
                                Product::normalizeColorCode($row['color_code'] ?? null) ?? '',
                                ! empty($row['base_type'])
                                    ? 'type:'.$row['base_type']
                                    : trim((string) ($row['color_name'] ?? '')),
                                $row['size_volume'] ?? '',
                            ]);

                            if (isset($seen[$key])) {
                                $fail('Each colour can only be listed once per size.');

                                return;
                            }

                            $seen[$key] = true;
                        }
                    },
                ])
                ->schema([
                    // A mixing base is chosen by TYPE, never by typing a hex:
                    // the type fixes its preview colour, how strongly colorant
                    // shows in it, and its default capacity — all calibrated
                    // in one place, config/paint.php "base_types". A hand-typed
                    // hex could not say that a deep base makes colour deeper.
                    Select::make('base_type')
                        ->label('Base type')
                        ->options(fn () => \App\Models\ProductVariant::baseTypeOptions())
                        ->required(fn ($get) => (bool) $get('../../is_mixing_base'))
                        ->visible(fn ($get) => (bool) $get('../../is_mixing_base'))
                        ->live()
                        ->columnSpan(2)
                        ->helperText('Sets the preview colour and how colour shows in this base. White for light colours; Deep or Accent for strong ones.'),

                    TextInput::make('color_name')
                        ->label('Color Name')
                        ->placeholder('e.g. Burnt Sienna')
                        ->maxLength(100)
                        ->columnSpan(2)
                        ->hidden(fn ($get) => (bool) $get('../../is_mixing_base'))
                        ->helperText('Leave both colour fields empty for products sold in no particular colour — thinners, tools.'),

                    TextInput::make('color_code')
                        ->label('Color Code')
                        ->placeholder('e.g. B-1408')
                        ->maxLength(40)
                        ->hidden(fn ($get) => (bool) $get('../../is_mixing_base'))
                        ->helperText('The manufacturer\'s code on the can / shade card.'),

                    // Not on a base row: the base TYPE sets its colour.
                    ColorPicker::make('hex_code')
                        ->label('Screen Preview')
                        ->hidden(fn ($get) => (bool) $get('../../is_mixing_base'))
                        ->helperText('Approximate only — the code and name are the paint\'s real identity.'),

                    // Sits under the picker of THIS row and writes into it: the
                    // blade derives the sibling's state path from its own, so it
                    // works unchanged inside the repeater. Sampling a photo is
                    // never exact (white balance, lighting), which is fine for a
                    // preview swatch but must not be sold as a measurement.
                    ViewField::make('hex_picker')
                        ->view('filament.forms.color-from-image')
                        ->hiddenLabel()
                        ->dehydrated(false)
                        ->columnSpanFull()
                        ->hidden(fn ($get) => (bool) $get('../../is_mixing_base')),

                    TextInput::make('size_volume')
                        ->label('Size / Volume')
                        ->placeholder('e.g. 4L')
                        ->required()
                        ->maxLength(30),

                    // The number behind size_volume. Filled from the size on
                    // save when left blank; on a base it caps the colorant
                    // (ml per litre x litres), so it is shown and required.
                    TextInput::make('volume_liters')
                        ->label('Volume (litres)')
                        ->numeric()
                        ->minValue(0.001)
                        ->suffix('L')
                        ->visible(fn ($get) => (bool) $get('../../is_mixing_base'))
                        ->required(fn ($get) => (bool) $get('../../is_mixing_base'))
                        ->helperText('Litres in one can, e.g. 4 for a 4L can. Used to limit how much colorant fits.'),

                    TextInput::make('max_tint_ml_per_liter')
                        ->label('Max colorant')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(9999)
                        ->suffix('ml per L')
                        ->placeholder(fn ($get) => (string) (config('paint.mix.base_types.'.$get('base_type').'.max_tint_ml_per_liter')
                            ?? config('paint.mix.default_max_tint_ml_per_liter')))
                        ->visible(fn ($get) => (bool) $get('../../is_mixing_base'))
                        ->helperText('Leave blank for the base type\'s default. Fill in only if the can says otherwise.'),

                    TextInput::make('price')
                        ->label('Price')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₱'),

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Image')
                    ->circular(),
                TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Product $record) => $record->brand?->brand_name),
                // One badge per category — a product can sit in several.
                TextColumn::make('categories.category_name')
                    ->label('Categories')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                // Colours now live on the variants, so this counts the distinct
                // shades rather than showing a single swatch. A custom-colour
                // product has no colour of its own; the badge says so, so an
                // empty cell is not read as "not filled in yet".
                TextColumn::make('colors')
                    ->label('Colors')
                    ->badge()
                    ->color(fn (Product $record) => $record->is_mixing_base ? 'info' : 'gray')
                    ->state(function (Product $record) {
                        if ($record->is_mixing_base) {
                            return ['Mixing base'];
                        }

                        $colors = collect($record->colors);

                        // Long shade cards would push every other column off
                        // the screen, so past three it becomes a count.
                        return $colors->count() > 3
                            ? [$colors->count().' colors']
                            : $colors->pluck('label')->all();
                    })
                    ->placeholder('—')
                    ->tooltip(fn (Product $record) => $record->is_mixing_base
                        ? 'Hidden from the catalogue — sold only on the mixing screen'
                        : (collect($record->colors)->pluck('label')->implode(', ') ?: null)),
                // One badge per DISTINCT size, e.g. [1L] [4L] [16L]. Reading
                // it off the relation gave a badge per variant, so a line in
                // six shades showed the same three sizes six times over.
                TextColumn::make('size_volume')
                    ->label('Sizes')
                    ->state(fn (Product $record) => $record->distinctSizes()->all())
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
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
                        'low_stock' => 'warning',
                        default => 'success',
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
                        'attention' => 'Needs Attention (Low + Out)',
                        'out_of_stock' => 'Has Out-of-Stock Size',
                        'low_stock' => 'Has Low-Stock Size',
                        'in_stock' => 'Healthy',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'attention' => $query->lowStock(),
                        'out_of_stock' => $query->outOfStock(),
                        'low_stock' => $query->whereHas('variants', fn ($q) => $q
                            ->where('is_archived', false)
                            ->where('stock', '>', 0)
                            ->whereColumn('stock', '<=', 'low_stock_threshold')),
                        'in_stock' => $query->whereDoesntHave('variants', fn ($q) => $q
                            ->where('is_archived', false)
                            ->whereColumn('stock', '<=', 'low_stock_threshold')),
                        default => $query,
                    }),

                SelectFilter::make('is_mixing_base')
                    ->label('Type')
                    ->options([
                        '0' => 'Catalogue products',
                        '1' => 'Mixing bases',
                    ]),

                SelectFilter::make('brand')
                    ->relationship('brand', 'brand_name'),
                // Matches products that carry the category among any of theirs.
                SelectFilter::make('categories')
                    ->label('Category')
                    ->relationship('categories', 'category_name'),
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
                EditAction::make(),
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
                    ->action(fn (Product $record) => $record->update(['is_archived' => ! $record->is_archived])),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('archiveSelected')
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
        // The Colors / Sizes / Price / Total Stock columns all read from
        // variants; Categories reads the pivot.
        return parent::getEloquentQuery()->with(['variants', 'categories']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
