<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Notification;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

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

            TextInput::make('size_volume')
                ->label('Size / Volume')
                ->placeholder('e.g. 1L, 4L, 16L'),

            ColorPicker::make('hex_code')
                ->label('Paint Color'),

            TextInput::make('price')
                ->required()
                ->numeric()
                ->prefix('₱')
                ->label('Price'),

            TextInput::make('stock')
                ->required()
                ->numeric()
                ->default(0)
                ->label('Stock'),

            FileUpload::make('image')
                ->image()
                ->directory('products')
                ->label('Product Image')
                ->columnSpanFull(),
        ]);
    }

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
                ColorColumn::make('hex_code')
                    ->label('Color'),
                TextColumn::make('size_volume')
                    ->label('Size'),
                TextColumn::make('price')
                    ->label('Price')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('stock')
                    ->label('Stock')
                    ->sortable()
                    ->color(fn ($state) => $state < 10 ? 'danger' : 'success'),
                // Archive status badge
                TextColumn::make('is_archived')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Archived' : 'Active')
                    ->color(fn ($state) => $state ? 'gray' : 'success'),
            ])
            ->filters([
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

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}