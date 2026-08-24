<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Services\OrderCancellationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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

            // 'cancelled' is deliberately absent — cancelling goes through the
            // Cancel action so a reason is always captured and stock is always
            // restored. An already-cancelled order keeps showing its status.
            Select::make('status')
                ->options(fn ($record) => $record?->status === 'cancelled'
                    ? ['cancelled' => 'Cancelled']
                    : [
                        'pending'          => 'Pending',
                        'processing'       => 'Processing',
                        'shipped'          => 'Shipped',
                        'ready_for_pickup' => 'Ready for Pickup',
                        'completed'        => 'Completed',
                    ])
                ->disabled(fn ($record) => $record?->status === 'cancelled')
                ->helperText(fn ($record) => $record?->status === 'cancelled'
                    ? null
                    : 'To cancel this order, use the Cancel Order action — it records a reason and returns stock.')
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

            Textarea::make('cancellation_reason')
                ->label('Cancellation Reason')
                ->disabled()
                ->visible(fn ($record) => filled($record?->cancellation_reason))
                ->helperText(fn ($record) => $record?->cancelled_at
                    ? 'Cancelled by ' . ($record->cancelledByCustomer() ? 'the customer' : ($record->cancelledBy?->name ?? 'the store'))
                        . ' on ' . $record->cancelled_at->format('M j, Y \a\t g:i A')
                    : null)
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
                    // Surface why straight in the list — a cancelled row is the
                    // one an admin most needs context on at a glance
                    ->description(fn($record) => $record->status === 'cancelled'
                        ? $record->cancellation_reason
                        : null)
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
                // The list page's tabs already split completed and cancelled off,
                // so this only narrows within the "To Process" work list —
                // offering Completed here would just return nothing.
                SelectFilter::make('status')
                    ->options([
                        'pending'          => 'Pending',
                        'processing'       => 'Processing',
                        'shipped'          => 'Shipped',
                        'ready_for_pickup' => 'Ready for Pickup',
                    ])
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? 'active') === 'active'),
                SelectFilter::make('order_type')
                    ->options([
                        'delivery' => 'Delivery',
                        'pickup'   => 'Pickup',
                    ]),
                Filter::make('created_at')
                    ->label('Order Date')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('From')
                            ->native(false)
                            ->maxDate(now()),
                        DatePicker::make('created_until')
                            ->label('Until')
                            ->native(false)
                            ->maxDate(now()),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null,
                            fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null,
                            fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null) {
                            $indicators[] = Indicator::make('From ' . Carbon::parse($data['created_from'])->format('M d, Y'))
                                ->removeField('created_from');
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators[] = Indicator::make('Until ' . Carbon::parse($data['created_until'])->format('M d, Y'))
                                ->removeField('created_until');
                        }
                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
           ->actions([
                \Filament\Actions\EditAction::make(),

                Action::make('cancelOrder')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Order $record) => OrderCancellationService::canCancel($record))
                    ->modalHeading('Cancel Order')
                    ->modalDescription('Stock will be returned and the customer will be messaged with the reason.')
                    ->modalSubmitActionLabel('Cancel Order')
                    ->schema([
                        Select::make('preset')
                            ->label('Reason')
                            ->options(
                                array_combine(Order::ADMIN_CANCEL_REASONS, Order::ADMIN_CANCEL_REASONS)
                                + ['other' => 'Other (type below)']
                            )
                            ->required()
                            ->live(),

                        Textarea::make('other_reason')
                            ->label('Reason')
                            ->placeholder('Tell the customer what happened...')
                            ->maxLength(500)
                            ->visible(fn ($get) => $get('preset') === 'other')
                            ->required(fn ($get) => $get('preset') === 'other'),
                    ])
                    ->action(function (Order $record, array $data) {
                        $reason = $data['preset'] === 'other'
                            ? trim((string) $data['other_reason'])
                            : $data['preset'];

                        OrderCancellationService::cancel($record, $reason, auth()->id());

                        Notification::make()
                            ->title('Order cancelled')
                            ->body('Stock has been returned and the customer has been messaged with the reason.')
                            ->success()
                            ->send();
                    }),
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