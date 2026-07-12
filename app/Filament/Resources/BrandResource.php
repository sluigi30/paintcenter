<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BrandResource\Pages;
use App\Models\Brand;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';
    protected static string|\UnitEnum|null $navigationGroup = 'Store Management';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('brand_name')
                ->required()
                ->maxLength(255)
                ->label('Brand Name'),

            FileUpload::make('image')
                ->image()
                ->directory('brands')
                ->label('Brand Logo')
                ->helperText('Shown as the brand tile in the mobile catalog. Square images look best; brands without a logo get a lettered tile.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Logo')
                    ->circular()
                    ->disk('public'),
                TextColumn::make('brand_name')
                    ->label('Brand Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products')
                    ->sortable(),
                TextColumn::make('is_archived')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Archived' : 'Active')
                    ->color(fn ($state) => $state ? 'gray' : 'success'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('is_archived')
                    ->label('Status')
                    ->options([
                        '0' => 'Active only',
                        '1' => 'Archived only',
                    ]),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                Action::make('toggleArchive')
                    ->label(fn (Brand $record) => $record->is_archived ? 'Unarchive' : 'Archive')
                    ->icon(fn (Brand $record) => $record->is_archived ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-archive-box')
                    ->color(fn (Brand $record) => $record->is_archived ? 'success' : 'warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Brand $record) => $record->is_archived ? 'Unarchive Brand' : 'Archive Brand')
                    ->modalDescription(fn (Brand $record) => $record->is_archived
                        ? 'This will make the brand active again.'
                        : 'This will hide the brand from the store. You can unarchive it anytime.')
                    ->action(fn (Brand $record) => $record->update(['is_archived' => !$record->is_archived])),
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
            'index'  => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'edit'   => Pages\EditBrand::route('/{record}/edit'),
        ];
    }
}
