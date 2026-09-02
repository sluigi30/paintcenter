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
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;
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
            // The counter mixes a custom colour from its hex — there is no
            // chart code to look up — so the colour has to be on this screen,
            // large, next to the size and the quantity. Without it the order
            // is unfulfillable. See CUSTOM_COLOR.md.
            Placeholder::make('mix_sheet')
                ->label('Items to prepare')
                ->columnSpanFull()
                ->content(fn ($record) => static::mixSheet($record)),

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

    /**
     * What the counter has to hand over, colour included.
     *
     * Rendered as markup rather than a disabled Repeater because the swatch
     * is the point: a custom line has to show a large block of the actual
     * colour beside its hex, and a form field cannot do that.
     */
    protected static function mixSheet(?Order $record): HtmlString
    {
        if (! $record?->exists) {
            return new HtmlString('<p style="font-size:.875rem;opacity:.6">No items yet.</p>');
        }

        $record->loadMissing(['orderItems.product', 'orderItems.variant']);
        $rows = '';

        foreach ($record->orderItems as $item) {
            $name = e($item->product?->description ?? 'Deleted product');
            $size = e($item->size_volume ?? '—');
            $qty  = (int) $item->quantity;

            if ($item->custom_hex) {
                $hex   = e($item->custom_hex);
                $label = $item->custom_color_name
                    ? e($item->custom_color_name)
                    : 'Custom colour';
                // '' means the paint line makes no base distinction.
                $base     = ProductResource::BASE_SHORT[$item->variant?->base_code ?? ''] ?? '';
                $baseText = $base !== '' ? ' &middot; ' . e($base) . ' base' : '';

                // More than one can of one colour must come out of a single
                // batch — cans mixed separately differ visibly on a wall.
                $batch = $qty > 1
                    ? '<div style="margin-top:.4rem;font-size:.75rem;font-weight:600;color:#b45309">'
                        . "Mix all {$qty} cans as ONE batch</div>"
                    : '';

                $rows .= <<<HTML
                    <div style="display:flex;gap:.85rem;align-items:center;padding:.75rem;border:1px solid rgba(128,128,128,.25);border-radius:.5rem;margin-bottom:.5rem">
                        <div style="width:56px;height:56px;flex:none;border-radius:.375rem;background:{$hex};border:1px solid rgba(0,0,0,.2)"></div>
                        <div style="min-width:0">
                            <div style="font-weight:600">{$name}</div>
                            <div style="font-size:.875rem">
                                {$label} &middot;
                                <span style="font-family:ui-monospace,monospace">{$hex}</span>
                            </div>
                            <div style="font-size:.875rem;opacity:.75">
                                {$size} &middot; {$qty} can(s){$baseText}
                            </div>
                            {$batch}
                        </div>
                    </div>
                HTML;
            } else {
                $swatch = $item->product?->hex_code
                    ? 'background:' . e($item->product->hex_code)
                    : 'background:repeating-linear-gradient(45deg,#ccc,#ccc 4px,#eee 4px,#eee 8px)';

                $color = e(collect([$item->product?->color_code, $item->product?->color_name])
                    ->filter()->implode(' · ')) ?: 'Ready-mixed';

                $rows .= <<<HTML
                    <div style="display:flex;gap:.85rem;align-items:center;padding:.75rem;border:1px solid rgba(128,128,128,.15);border-radius:.5rem;margin-bottom:.5rem">
                        <div style="width:56px;height:56px;flex:none;border-radius:.375rem;{$swatch};border:1px solid rgba(0,0,0,.15)"></div>
                        <div style="min-width:0">
                            <div style="font-weight:600">{$name}</div>
                            <div style="font-size:.875rem;opacity:.75">{$color}</div>
                            <div style="font-size:.875rem;opacity:.75">{$size} &middot; {$qty} can(s)</div>
                        </div>
                    </div>
                HTML;
            }
        }

        return new HtmlString($rows ?: '<p style="font-size:.875rem;opacity:.6">No items.</p>');
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