<?php

namespace App\Services;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Rings the admin panel's notification bell when a customer places an order.
 *
 * The orders table does not announce itself: an admin sitting on any other
 * screen had no way of learning a new order had arrived short of reloading.
 * The bell already polls every 30s (see AdminPanelProvider), so a database
 * notification is the cheapest live signal available without websockets.
 *
 * Called from OrderController::store AFTER the commit — deliberately not from
 * an observer. Two reasons: an observer's `created` fires inside the
 * transaction, so a rollback would leave admins chasing an order that never
 * existed; and orders typed in by an admin through the Filament create form
 * would ring that same admin's bell about their own typing.
 *
 * The customer's own announcement is OrderMessageService::orderPlaced() — this
 * is the store side of the same moment.
 */
class AdminOrderAlertService
{
    public static function orderPlaced(Order $order): void
    {
        $notification = Notification::make()
            ->title('New Order')
            ->icon('heroicon-o-shopping-bag')
            ->success()
            ->body(static::summary($order))
            ->actions([
                Action::make('view')
                    ->label('View order')
                    ->button()
                    // RELATIVE on purpose. An absolute URL is built from the
                    // host of the request that created it — and that request
                    // comes from the mobile app, pointed at the LAN IP or the
                    // Cloud domain, never at whatever address the admin has
                    // the panel open on. A root-relative path resolves against
                    // the admin's own browser instead.
                    //
                    // The panel is PINNED for a related reason: Resource::getUrl()
                    // resolves against whatever panel the CURRENT request is in,
                    // and these notifications are raised from outside the admin
                    // panel — the API, and (for deliveryExhausted) the driver
                    // panel. Left unpinned it asks for
                    // filament.driver.resources.orders.edit, which does not exist,
                    // and the driver's action dies with a 500.
                    ->url(OrderResource::getUrl('edit', ['record' => $order], isAbsolute: false, panel: 'admin'))
                    ->markAsRead(),
            ]);

        // notifyNow, not sendToDatabase — the latter queues, and an alert that
        // waits on a worker nobody is running is not an alert. Same reasoning
        // as the stock alerts in ProductVariantObserver.
        foreach (User::admins()->where('is_archived', false)->get() as $admin) {
            $admin->notifyNow($notification->toDatabase());
        }
    }

    /**
     * A delivery that has run out of attempts.
     *
     * The driver's Couldn't Deliver button is withdrawn at the cap, so from
     * here the order can only move if an admin acts — cancel it through
     * OrderCancellationService (stock returns, the customer is told why) or
     * reassign it. Without this alert it would sit `shipped` indefinitely: off
     * every admin work list, because `shipped` reads as "out on the van".
     */
    public static function deliveryExhausted(Order $order, string $reason): void
    {
        $notification = Notification::make()
            ->title('Delivery failed — needs a decision')
            ->icon('heroicon-o-exclamation-triangle')
            ->warning()
            ->body(sprintf(
                '<strong>%s</strong> could not be delivered after %d attempts. Last reason: %s. Cancel it or assign another driver.',
                e($order->created_at->format('M j, Y \a\t g:i A')),
                (int) $order->failed_attempts,
                e($reason),
            ))
            ->actions([
                Action::make('view')
                    ->label('View order')
                    ->button()
                    ->url(OrderResource::getUrl('edit', ['record' => $order], isAbsolute: false, panel: 'admin'))
                    ->markAsRead(),
            ]);

        foreach (User::admins()->where('is_archived', false)->get() as $admin) {
            $admin->notifyNow($notification->toDatabase());
        }
    }

    /**
     * Bodies render as sanitized HTML, so <strong> rather than markdown.
     */
    private static function summary(Order $order): string
    {
        $customer = trim(($order->user?->first_name ?? '') . ' ' . ($order->user?->last_name ?? ''));
        $items    = (int) $order->orderItems()->sum('quantity');
        $type     = $order->order_type === 'delivery' ? 'delivery' : 'pickup';

        return sprintf(
            '<strong>%s</strong> placed a %s order — %d %s, <strong>₱%s</strong>.',
            e($customer !== '' ? $customer : 'A customer'),
            $type,
            $items,
            $items === 1 ? 'item' : 'items',
            number_format((float) $order->total_amount, 2),
        );
    }
}
