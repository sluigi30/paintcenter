<?php

namespace App\Filament\Driver\Resources;

use App\Filament\Driver\Resources\DeliveryResource\Pages;
use App\Models\Order;
use App\Services\DeliveryService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use App\Services\DeliveryProofService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * The driver's own deliveries.
 *
 * Purpose-built rather than a filtered OrderResource. A driver needs the
 * customer's name, their phone, the address, what is in the box and what to
 * collect — not the cancel action, not the edit form, not revenue. Reusing the
 * admin's resource would mean hiding most of it, and every column added there
 * later would arrive here uninvited.
 */
class DeliveryResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'My Deliveries';

    protected static ?string $modelLabel = 'Delivery';

    protected static ?string $slug = 'deliveries';

    // A driver records what happened; they never author or remove an order.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    /**
     * The one scope everything in this panel rests on.
     *
     * Because it is applied here rather than per-page, a driver who hand-writes
     * a URL for somebody else's order gets a "record not found" — a 404, not a
     * 403. Order ids are a plain auto-increment, so a 403 on a row that exists
     * is a difference anyone can measure by counting. Same rule as
     * MessageAttachmentController.
     *
     * Pickups are excluded outright: they are handed over at the counter and
     * are never assigned to anybody.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('driver_id', auth()->id())
            ->where('order_type', 'delivery')
            ->with(['user', 'payment', 'orderItems']);
    }

    /** Deliveries still to be dealt with, for the sidebar badge. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereIn('status', ['processing', 'shipped'])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Orders are named by when they were placed, never by id — so
                // the driver and the customer are talking about the same thing
                // when one phones the other.
                TextColumn::make('created_at')
                    ->label('Order placed')
                    ->dateTime('M j, Y · g:i A')
                    ->description(fn (Order $record) => $record->user?->name ?: 'Customer')
                    ->sortable(),

                TextColumn::make('shipping_address')
                    ->label('Deliver to')
                    ->wrap()
                    ->placeholder('Address on file'),

                TextColumn::make('total_amount')
                    ->label('To collect')
                    ->money('PHP')
                    // Only COD is money the driver handles. Anything already
                    // paid online shows as a dash, so a glance down the column
                    // is a glance at the cash they should be carrying.
                    ->formatStateUsing(fn ($state, Order $record) => $record->isCashOnDelivery()
                        ? '₱' . number_format((float) $state, 2)
                        : '—')
                    ->badge()
                    ->color(fn (Order $record) => $record->isCashOnDelivery() ? 'warning' : 'gray'),

                TextColumn::make('failed_attempts')
                    ->label('Attempts')
                    ->badge()
                    ->color('danger')
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? null) !== 'history')
                    ->formatStateUsing(fn ($state) => $state . ' of ' . DeliveryService::MAX_ATTEMPTS)
                    ->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                ViewAction::make(),
                static::pickUpAction(),
                static::deliverAction(),
                static::failAction(),
            ])
            ->defaultSort('created_at', 'asc')
            // Oldest first: the order that has been waiting longest is the one
            // to do next. Everywhere else in this app sorts newest first, which
            // is right for a list you are reading and wrong for a queue you are
            // working through.
            ->poll('30s');
    }

    /**
     * The delivery detail screen — read one-handed, outdoors, on a phone.
     *
     * The phone number is a tel: link because the single most useful thing a
     * driver can do from a doorstep is call the person inside. Address and
     * items are the other two, and there is deliberately nothing else: no
     * pricing breakdown, no customer email, no order history.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')
                ->columns(2)
                ->schema([
                    TextEntry::make('user.name')
                        ->label('Name')
                        ->placeholder('—'),

                    TextEntry::make('user.phone')
                        ->label('Phone')
                        ->placeholder('No number on file')
                        ->formatStateUsing(fn ($state) => $state
                            ? new HtmlString('<a href="tel:' . e(preg_replace('/\s+/', '', $state)) . '" style="font-weight:600;text-decoration:underline">' . e($state) . '</a>')
                            : null),

                    // The address is the primary thing on screen; the map link
                    // sits under it. There are no coordinates in this schema —
                    // see DELIVERY_ROLE.md 4b — so this is a SEARCH handed to
                    // whatever map the browser opens, and it is exactly as
                    // accurate as the address is. A pin geocoded from
                    // "Pilar, Bataan" would look precise and be kilometres
                    // wrong, which a driver would trust.
                    TextEntry::make('shipping_address')
                        ->label('Deliver to')
                        ->placeholder('Address on file')
                        ->columnSpanFull()
                        ->formatStateUsing(function ($state, Order $record) {
                            if (blank($state) && ! $record->hasLocationPin()) {
                                return null;
                            }

                            // A PIN when the customer set one, the address
                            // string otherwise. The pin is the whole point of
                            // Phase 5: a search for "Brgy. Poblacion, Pilar"
                            // resolves to a polygon and lands the driver in the
                            // wrong street.
                            $query = $record->hasLocationPin()
                                ? $record->delivery_lat . ',' . $record->delivery_lng
                                : trim((string) $state);

                            $url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($query);

                            $html = e((string) $state);

                            if ($record->hasLocationPin()) {
                                // Quoted, never hidden. A ±480 m fix taken
                                // indoors is barely better than the town
                                // centre, and a driver who trusts it wastes
                                // the trip the pin was meant to save.
                                $accuracy = $record->location_accuracy
                                    ? ' (±' . $record->location_accuracy . ' m)'
                                    : '';

                                $html .= '<br><span style="color:#15803d;font-weight:600">Pinned by the customer'
                                    . e($accuracy) . '</span>';
                            }

                            return new HtmlString(
                                $html
                                . '<br><a href="' . e($url) . '" target="_blank" rel="noopener noreferrer"'
                                . ' style="display:inline-flex;align-items:center;gap:.35rem;margin-top:.4rem;'
                                . 'font-weight:700;color:#1d4ed8;text-decoration:underline">Navigate &rarr;</a>'
                            );
                        }),
                ]),

            Section::make('Landmark / directions')
                ->visible(fn (Order $record) => filled($record->delivery_landmark))
                ->schema([
                    TextEntry::make('delivery_landmark')
                        ->hiddenLabel()
                        ->columnSpanFull(),
                ]),

            Section::make('What to hand over')
                ->schema([
                    TextEntry::make('order_items_summary')
                        ->hiddenLabel()
                        ->state(fn (Order $record) => new HtmlString(static::itemLines($record)))
                        ->columnSpanFull(),
                ]),

            Section::make('Payment')
                ->columns(2)
                ->schema([
                    TextEntry::make('payment.payment_method')
                        ->label('Method')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ($state) {
                            'cod'   => 'Cash on Delivery',
                            'gcash' => 'GCash (paid online)',
                            'card'  => 'Card (paid online)',
                            default => ucfirst((string) $state),
                        })
                        ->color(fn (Order $record) => $record->isCashOnDelivery() ? 'warning' : 'gray'),

                    TextEntry::make('total_amount')
                        ->label(fn (Order $record) => $record->isCashOnDelivery() ? 'Collect' : 'Order total')
                        ->money('PHP')
                        ->weight('bold'),
                ]),

            Section::make('Delivery')
                ->columns(2)
                ->visible(fn (Order $record) => $record->picked_up_at || $record->failed_attempts > 0)
                ->schema([
                    TextEntry::make('picked_up_at')
                        ->label('Picked up')
                        ->dateTime('M j, g:i A')
                        ->placeholder('—'),

                    TextEntry::make('delivered_at')
                        ->label('Delivered')
                        ->dateTime('M j, g:i A')
                        ->placeholder('—'),

                    TextEntry::make('failed_attempts')
                        ->label('Failed attempts')
                        ->visible(fn (Order $record) => $record->failed_attempts > 0)
                        ->formatStateUsing(fn ($state) => $state . ' of ' . DeliveryService::MAX_ATTEMPTS)
                        ->badge()
                        ->color('danger'),

                    TextEntry::make('delivery_note')
                        ->label('Last note')
                        ->placeholder('—')
                        ->visible(fn (Order $record) => filled($record->delivery_note)),
                ]),
        ]);
    }

    /** One line per can, with its colour swatch — what actually goes in the box. */
    protected static function itemLines(Order $record): string
    {
        $html = '';

        foreach ($record->orderItems as $item) {
            $name  = e($item->product?->name ?: 'Paint');
            $color = $item->display_color ? ' · ' . e($item->display_color) : '';
            $size  = $item->size_volume ? ' · ' . e($item->size_volume) : '';
            $hex   = $item->hex_code ?: ($item->custom_hex ?: '#cccccc');

            $html .= <<<HTML
                <div style="display:flex;align-items:center;gap:.6rem;padding:.35rem 0;border-bottom:1px solid rgba(128,128,128,.18)">
                    <span style="width:18px;height:18px;flex:none;border-radius:4px;background:{$hex};border:1px solid rgba(0,0,0,.2)"></span>
                    <span style="font-weight:700;min-width:2.2rem">{$item->quantity}&times;</span>
                    <span style="flex:1">{$name}<span style="opacity:.7">{$color}{$size}</span></span>
                </div>
            HTML;
        }

        return $html ?: '<span style="opacity:.6">No items recorded.</span>';
    }

    /** processing → shipped. The goods are now with the driver. */
    public static function pickUpAction(): Action
    {
        return Action::make('pickUp')
            ->label('Picked Up')
            ->icon('heroicon-o-archive-box-arrow-down')
            ->color('primary')
            ->visible(fn (Order $record) => $record->status === 'processing')
            ->requiresConfirmation()
            ->modalHeading('Confirm you have this order')
            ->modalDescription('The customer is told their order is on its way.')
            ->modalSubmitActionLabel('I have it')
            ->action(fn (Order $record) => static::run(
                fn () => DeliveryService::pickUp($record, auth()->user()),
                'On its way',
                'The customer has been told their order is out for delivery.',
            ));
    }

    /** shipped → completed, with the COD cash confirmed if there is any. */
    public static function deliverAction(): Action
    {
        return Action::make('deliver')
            ->label('Delivered')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Order $record) => $record->status === 'shipped')
            ->modalHeading('Mark as delivered')
            ->modalSubmitActionLabel('Mark as Delivered')
            ->schema(fn (Order $record) => array_merge(
                $record->isCashOnDelivery()
                    ? [
                        // Required, and the server enforces it again in
                        // DeliveryService::deliver(). Nothing else in the system
                        // ever marks a COD payment paid, so an unconfirmed handover
                        // leaves the store unable to say who holds the money.
                        Checkbox::make('cash_collected')
                            ->label('I collected ₱' . number_format((float) $record->total_amount, 2) . ' in cash')
                            ->helperText("If the customer couldn't pay, use Couldn't Deliver instead — the items come back with you.")
                            ->accepted()
                            ->required(),
                    ]
                    : [],
                [
                    // The same requirement the app enforces, because both end
                    // up in DeliveryService::deliver().
                    //
                    // storeFiles(false) is the important part: Filament would
                    // otherwise write the file itself and hand back a PATH,
                    // leaving two different pieces of code storing proofs in
                    // two different ways. Off, the state is Livewire's
                    // TemporaryUploadedFile — an Illuminate UploadedFile — so
                    // DeliveryProofService does the writing for both clients,
                    // on the disk it chooses, under the naming it chooses.
                    FileUpload::make('proof')
                        ->label('Photo of the delivery')
                        ->helperText('Required. A photo of the handover — the items at the door, or with the customer.')
                        ->image()
                        ->storeFiles(false)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(DeliveryProofService::MAX_FILE_KB)
                        ->required(),
                ],
            ))
            ->action(fn (Order $record, array $data) => static::run(
                fn () => DeliveryService::deliver(
                    $record,
                    auth()->user(),
                    (bool) ($data['cash_collected'] ?? false),
                    static::uploadedProof($data['proof'] ?? null),
                ),
                'Delivered',
                'Thanks — the customer has been told the order is complete.',
            ));
    }

    /**
     * Tried, came back with it. No status change and no new status: see
     * DELIVERY_ROLE.md. Withdrawn once the attempt cap is reached, because from
     * there the decision belongs to the store.
     */
    public static function failAction(): Action
    {
        return Action::make('failDelivery')
            ->label("Couldn't Deliver")
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->visible(fn (Order $record) => $record->isOutForDelivery()
                && ! DeliveryService::attemptsExhausted($record))
            ->modalHeading("Couldn't deliver this order")
            ->modalDescription('The customer is told what happened, and the order stays on your list to try again.')
            ->modalSubmitActionLabel('Record attempt')
            ->schema([
                Select::make('preset')
                    ->label('What happened?')
                    ->options(
                        array_combine(DeliveryService::FAILURE_REASONS, DeliveryService::FAILURE_REASONS)
                        + ['other' => 'Other (type below)']
                    )
                    ->required()
                    ->live(),

                Textarea::make('other_reason')
                    ->label('What happened?')
                    ->maxLength(500)
                    ->visible(fn ($get) => $get('preset') === 'other')
                    ->required(fn ($get) => $get('preset') === 'other'),
            ])
            ->action(function (Order $record, array $data) {
                $reason = $data['preset'] === 'other'
                    ? trim((string) $data['other_reason'])
                    : $data['preset'];

                static::run(function () use ($record, $reason) {
                    $attempts = DeliveryService::recordFailedAttempt($record, auth()->user(), $reason);

                    Notification::make()
                        ->title('Attempt recorded')
                        ->body($attempts >= DeliveryService::MAX_ATTEMPTS
                            ? 'That was the last attempt. The store has been alerted and will take it from here — please return the items.'
                            : 'The customer has been told. The order stays on your list to try again.')
                        ->warning()
                        ->send();
                });
            });
    }

    /**
     * Pull the UploadedFile out of a FileUpload's state.
     *
     * With storeFiles(false) the state is an array keyed by upload uuid, even
     * for a single file, and its values are TemporaryUploadedFile instances.
     * Anything else — a plain string path from a component someone later
     * switches back to storing, or an empty array — yields null, and
     * DeliveryService refuses the delivery rather than completing one with no
     * photo attached.
     */
    protected static function uploadedProof(mixed $state): ?\Illuminate\Http\UploadedFile
    {
        if ($state instanceof \Illuminate\Http\UploadedFile) {
            return $state;
        }

        if (is_array($state)) {
            foreach ($state as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Run a DeliveryService call and turn its two failure modes into words.
     *
     * A DomainException here is a rule the server enforced that the screen
     * thought was satisfied — usually because somebody else moved the order
     * while this driver was looking at it. Showing the message is better than a
     * 500, and better than silence.
     */
    protected static function run(callable $call, ?string $title = null, ?string $body = null): void
    {
        try {
            $result = $call();
        } catch (DomainException $e) {
            Notification::make()
                ->title('Could not do that')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($result instanceof \App\Services\StatusChange && ! $result->succeeded()) {
            Notification::make()
                ->title('Order already moved')
                ->body('Someone at the store changed this order. Pull down to refresh.')
                ->warning()
                ->send();

            return;
        }

        if ($title !== null) {
            Notification::make()->title($title)->body($body)->success()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveries::route('/'),
            'view'  => Pages\ViewDelivery::route('/{record}'),
        ];
    }
}
