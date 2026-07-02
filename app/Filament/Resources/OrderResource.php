<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static string|\UnitEnum|null $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 1;
    
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('user_id')
                ->label('User ID')
                ->disabled(),

            Select::make('status')
                ->options([
                    'pending'          => 'Pending',
                    'processing'       => 'Processing',
                    'shipped'          => 'Shipped',
                    'ready_for_pickup' => 'Ready for Pickup',
                    'completed'        => 'Completed',
                    'cancelled'        => 'Cancelled',
                ])
                ->required(),

            Select::make('order_type')
                ->options([
                    'delivery' => 'Delivery',
                    'pickup'   => 'Pickup',
                ])
                ->disabled(),

            TextInput::make('total_amount')
                ->label('Total Amount')
                ->prefix('₱')
                ->disabled(),

            Textarea::make('shipping_address')
                ->label('Shipping Address')
                ->disabled()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Order #')
                    ->sortable(),
                TextColumn::make('user.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn($record) => $record->user->first_name . ' ' . $record->user->last_name)
                    ->searchable(),
                TextColumn::make('order_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn($state) => $state === 'delivery' ? 'info' : 'success'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn($state) => match($state) {
                        'pending'          => 'warning',
                        'processing'       => 'info',
                        'shipped'          => 'primary',
                        'ready_for_pickup' => 'primary',
                        'completed'        => 'success',
                        'cancelled'        => 'danger',
                        default            => 'gray',
                    }),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('payment.payment_method')
                    ->label('Payment')
                    ->badge(),
                TextColumn::make('payment.payment_status')
                    ->label('Payment Status')
                    ->badge()
                    ->color(fn($state) => match($state) {
                        'paid'    => 'success',
                        'pending' => 'warning',
                        'failed'  => 'danger',
                        default   => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'          => 'Pending',
                        'processing'       => 'Processing',
                        'shipped'          => 'Shipped',
                        'ready_for_pickup' => 'Ready for Pickup',
                        'completed'        => 'Completed',
                        'cancelled'        => 'Cancelled',
                    ]),
                SelectFilter::make('order_type')
                    ->options([
                        'delivery' => 'Delivery',
                        'pickup'   => 'Pickup',
                    ]),
            ])
           ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'edit'  => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}