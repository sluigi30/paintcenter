<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderCancellationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    /**
     * Orders waiting to be picked up by staff. Badges are rendered with the
     * sidebar, so this refreshes on navigation, not on a timer — the bell is
     * what announces an order live (see AdminOrderAlertService).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Orders waiting to be processed';
    }

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

            // Status is no longer a field to set. A dropdown of every status let
            // a delivery be marked ready_for_pickup, which the customer's
            // tracker cannot place — it showed the order as untouched. It moves
            // one step at a time now, through the row actions, along
            // Order::STATUS_FLOW_BY_TYPE.
            Placeholder::make('status_display')
                ->label('Status')
                ->content(fn (?Order $record) => $record ? static::statusSummary($record) : '—'),

            Select::make('order_type')
                ->options([
                    'delivery' => 'Delivery',
                    'pickup' => 'Pickup',
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
                    ? 'Cancelled by '.($record->cancelledByCustomer() ? 'the customer' : ($record->cancelledBy?->name ?? 'the store'))
                        .' on '.$record->cancelled_at->format('M j, Y \a\t g:i A')
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
    /**
     * A customer-composed mix, written out as something the counter can follow.
     *
     * Volumes are stated per can AND totalled, because the two answer different
     * questions: how much to pour, and what to pour it into. A 4L base with
     * three pints does not fit back in the 4L can.
     *
     * @param  Collection<int,OrderItem>  $lines
     */
    protected static function mixRecipe($lines): string
    {
        $first = $lines->first();
        $hex = e($first->custom_hex ?? '#CCCCCC');
        $label = $first->custom_color_name ? e($first->custom_color_name) : 'Unnamed mix';

        $total = 0.0;
        $parts = ['base' => '', 'tint' => ''];

        foreach ($lines->sortByDesc(fn ($l) => $l->mix_role === 'base' ? 1 : 0) as $line) {
            $qty = (int) $line->quantity;
            $each = (float) ($line->mix_liters ?? 0);
            $total += $each * $qty;

            $what = e($line->color_name ?: ($line->product?->name ?: 'Paint'));
            $size = e($line->size_volume ?? '');
            $vol = number_format($each, 3);
            $mult = $qty > 1 ? " &times; {$qty}" : '';

            $parts[$line->mix_role === 'base' ? 'base' : 'tint'] .= <<<HTML
                <div style="display:flex;align-items:center;gap:.5rem;padding:.15rem 0 .15rem 1rem">
                    <span style="width:14px;height:14px;flex:none;border-radius:3px;background:{$line->hex_code};border:1px solid rgba(0,0,0,.2)"></span>
                    <span style="flex:1">{$what} <span style="opacity:.6">({$size})</span></span>
                    <span style="font-family:ui-monospace,monospace">{$vol} L{$mult}</span>
                </div>
            HTML;
        }

        $totalText = number_format($total, 3);

        return <<<HTML
            <div style="padding:.85rem;border:2px solid #b45309;border-radius:.5rem;margin-bottom:.75rem">
                <div style="display:flex;gap:.85rem;align-items:center;margin-bottom:.6rem">
                    <div style="width:56px;height:56px;flex:none;border-radius:.375rem;background:{$hex};border:1px solid rgba(0,0,0,.2)"></div>
                    <div style="min-width:0">
                        <div style="font-size:.7rem;font-weight:700;letter-spacing:.05em;color:#b45309">MIX TO ORDER</div>
                        <div style="font-weight:600">{$label}</div>
                        <div style="font-size:.875rem;opacity:.75">
                            target <span style="font-family:ui-monospace,monospace">{$hex}</span>
                        </div>
                    </div>
                </div>

                <div style="font-size:.75rem;font-weight:700;opacity:.6;margin-top:.5rem">BASE</div>
                {$parts['base']}

                <div style="font-size:.75rem;font-weight:700;opacity:.6;margin-top:.5rem">ADD</div>
                {$parts['tint']}

                <div style="display:flex;justify-content:space-between;margin-top:.6rem;padding-top:.5rem;border-top:1px solid rgba(128,128,128,.25);font-weight:600">
                    <span>Total volume</span>
                    <span style="font-family:ui-monospace,monospace">{$totalText} L</span>
                </div>

                <div style="margin-top:.5rem;font-size:.75rem;font-weight:600;color:#b45309">
                    &#9888; Mix as ONE batch — separate batches differ visibly on a wall.<br>
                    &#9888; The target colour is an estimate. Match the recipe, not the swatch.
                </div>
            </div>
        HTML;
    }

    protected static function mixSheet(?Order $record): HtmlString
    {
        if (! $record?->exists) {
            return new HtmlString('<p style="font-size:.875rem;opacity:.6">No items yet.</p>');
        }

        $record->loadMissing(['orderItems.product', 'orderItems.variant']);
        $rows = '';

        // A mix is several lines that have to be POURED TOGETHER. One card per
        // line shows the counter three cans of the same predicted colour and no
        // instruction to combine them, which is exactly how three wrong cans
        // leave the shop. Recipes are printed first, as a block.
        [$mixed, $plain] = $record->orderItems->partition(fn ($i) => $i->mix_group !== null);

        foreach ($mixed->groupBy('mix_group') as $lines) {
            $rows .= static::mixRecipe($lines);
        }

        foreach ($plain as $item) {
            $name = e($item->product?->name ?: 'Deleted product');
            $size = e($item->size_volume ?? '—');
            $qty = (int) $item->quantity;

            if ($item->custom_hex) {
                $hex = e($item->custom_hex);
                $label = $item->custom_color_name
                    ? e($item->custom_color_name)
                    : 'Custom colour';
                // '' means the paint line makes no base distinction.
                $base = ProductResource::BASE_SHORT[$item->variant?->base_code ?? ''] ?? '';
                $baseText = $base !== '' ? ' &middot; '.e($base).' base' : '';

                // More than one can of one colour must come out of a single
                // batch — cans mixed separately differ visibly on a wall.
                $batch = $qty > 1
                    ? '<div style="margin-top:.4rem;font-size:.75rem;font-weight:600;color:#b45309">'
                        ."Mix all {$qty} cans as ONE batch</div>"
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
                // Read off the LINE, not the variant: colour is snapshotted
                // at checkout for the same reason size and price are, and a
                // recoloured or archived variant must not rewrite an order
                // the counter has already picked.
                $swatch = $item->hex_code
                    ? 'background:'.e($item->hex_code)
                    : 'background:repeating-linear-gradient(45deg,#ccc,#ccc 4px,#eee 4px,#eee 8px)';

                $color = e($item->color_label) ?: 'Ready-mixed';

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
            // An order can arrive at any moment and this is the screen staff
            // leave open. Without a poll the list is only as fresh as the last
            // reload. 30s matches the notification bell's own interval.
            ->poll('30s')
            ->columns([
                TextColumn::make('id')
                    ->label('Order #')
                    ->sortable(),
                TextColumn::make('user.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->user->first_name.' '.$record->user->last_name)
                    ->searchable(),
                TextColumn::make('order_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn ($state) => $state === 'delivery' ? 'info' : 'success'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // Surface why straight in the list — a cancelled row is the
                    // one an admin most needs context on at a glance
                    ->description(fn ($record) => $record->status === 'cancelled'
                        ? $record->cancellation_reason
                        : null)
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'shipped' => 'primary',
                        'ready_for_pickup' => 'primary',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
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
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
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
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'shipped' => 'Shipped',
                        'ready_for_pickup' => 'Ready for Pickup',
                    ])
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? 'active') === 'active'),
                SelectFilter::make('order_type')
                    ->options([
                        'delivery' => 'Delivery',
                        'pickup' => 'Pickup',
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
                            $indicators[] = Indicator::make('From '.Carbon::parse($data['created_from'])->format('M d, Y'))
                                ->removeField('created_from');
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators[] = Indicator::make('Until '.Carbon::parse($data['created_until'])->format('M d, Y'))
                                ->removeField('created_until');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->actions([
                EditAction::make(),

                static::advanceStatusAction(),
                static::revertStatusAction(),

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

    /**
     * Move the order one step along its own flow.
     *
     * One button rather than a dropdown: the work is linear, so the only
     * question is whether the next thing has happened yet. It names the step it
     * performs ("Out for Delivery"), because the admin is recording something
     * that happened rather than setting a field.
     */
    public static function advanceStatusAction(): Action
    {
        return Action::make('advanceStatus')
            ->label(fn (Order $record) => Order::STATUS_ADVANCE_LABELS[$record->nextStatus()] ?? 'Advance')
            ->icon('heroicon-o-arrow-right-circle')
            ->color('primary')
            ->visible(fn (Order $record) => $record->nextStatus() !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record) => 'Move to '.Order::statusLabel($record->nextStatus()).'?')
            ->modalDescription('The customer is messaged as soon as this is saved.')
            ->action(function (Order $record) {
                $next = $record->nextStatus();

                // Re-read rather than trusting the rendered button: the table
                // polls every 30s and two admins can be looking at the same
                // row. If someone moved it already, say so instead of pushing
                // it a second step along.
                if ($next === null || $record->fresh()->status !== $record->status) {
                    Notification::make()
                        ->title('Order already moved')
                        ->body('Someone else advanced this order. Refresh to see where it is.')
                        ->warning()
                        ->send();

                    return;
                }

                // OrderObserver announces the change to the customer.
                $record->update(['status' => $next]);

                Notification::make()
                    ->title('Order is now '.Order::statusLabel($next))
                    ->body('The customer has been told.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Undo a press that went one step too far.
     *
     * Deliberately one step back and no further, so correcting a slip cannot
     * turn into rewriting an order's history. The customer is messaged again —
     * they were already told the wrong thing, and silence would leave them
     * with it.
     */
    public static function revertStatusAction(): Action
    {
        return Action::make('revertStatus')
            ->label('Move Back')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (Order $record) => $record->previousStatus() !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record) => 'Move back to '.Order::statusLabel($record->previousStatus()).'?')
            ->modalDescription('For correcting a step taken by mistake. The customer is messaged about the change.')
            ->action(function (Order $record) {
                $previous = $record->previousStatus();

                if ($previous === null || $record->fresh()->status !== $record->status) {
                    Notification::make()
                        ->title('Order already moved')
                        ->body('Someone else changed this order. Refresh to see where it is.')
                        ->warning()
                        ->send();

                    return;
                }

                $record->update(['status' => $previous]);

                Notification::make()
                    ->title('Moved back to '.Order::statusLabel($previous))
                    ->success()
                    ->send();
            });
    }

    /** Where the order is, and what it is waiting for, on the edit page. */
    protected static function statusSummary(Order $record): HtmlString
    {
        $now = e(Order::statusLabel($record->status));
        $next = $record->nextStatus();

        if ($record->status === 'cancelled') {
            return new HtmlString("<strong>{$now}</strong>");
        }

        $hint = $next
            ? 'Next: '.e(Order::statusLabel($next))
            : 'This order has reached the end of its flow.';

        return new HtmlString("<strong>{$now}</strong><br><span style=\"opacity:.7\">{$hint}</span>");
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
