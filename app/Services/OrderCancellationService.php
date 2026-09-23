<?php

namespace App\Services;

use App\Models\InventoryLog;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * The single cancellation path, shared by the customer's mobile cancel and the
 * admin's Cancel action. Both must restore stock, log the inventory movement,
 * and record who cancelled and why — keeping that in one place stops the two
 * from drifting apart.
 */
class OrderCancellationService
{
    /** Past this point the goods have moved and cancelling is a manual matter. */
    public const CANCELLABLE_STATUSES = ['pending', 'processing'];

    /**
     * An order carrying custom-tinted lines closes earlier. Mixing happens
     * during `processing`, and a tinted can cannot be un-tinted or resold —
     * once the machine has dispensed, the base is spent and the shop eats it.
     */
    public const CUSTOM_CANCELLABLE_STATUSES = ['pending'];

    /**
     * The admin's rule. Staff know whether a can has actually been mixed yet;
     * they may still need to cancel during processing for stock reasons, so
     * this is deliberately NOT narrowed for custom orders.
     *
     * The one exception past `processing` is a delivery that has exhausted its
     * attempts. Normally a shipped order is uncancellable because the goods
     * have moved and nobody here knows where they are — but an exhausted
     * delivery is precisely the case where we DO know: the driver tried
     * DeliveryService::MAX_ATTEMPTS times, could not hand it over, and was told
     * to bring it back. Without this the admin is sent an alert saying "cancel
     * it or assign another driver" and finds no Cancel button, with the real
     * route being an undocumented Move Back to Processing first.
     */
    public static function canCancel(Order $order): bool
    {
        if (in_array($order->status, self::CANCELLABLE_STATUSES, true)) {
            return true;
        }

        return $order->status === 'shipped' && DeliveryService::attemptsExhausted($order);
    }

    /** The customer's rule — narrower, because they cannot see the counter. */
    public static function canCustomerCancel(Order $order): bool
    {
        $allowed = $order->has_custom_items
            ? self::CUSTOM_CANCELLABLE_STATUSES
            : self::CANCELLABLE_STATUSES;

        return in_array($order->status, $allowed, true);
    }

    public static function cancel(Order $order, string $reason, int $cancelledById): void
    {
        DB::transaction(function () use ($order, $reason, $cancelledById) {
            foreach ($order->orderItems as $item) {
                // Restore stock on the exact size that was ordered
                $item->variant?->increment('stock', $item->quantity);

                InventoryLog::create([
                    'product_id'         => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'action_name'        => 'order_cancelled',
                    'quantity_changed'   => $item->quantity,
                ]);
            }

            // One update so OrderObserver sees the reason and the canceller
            // alongside the new status — the customer message needs all three
            $order->update([
                'status'              => 'cancelled',
                'cancellation_reason' => trim($reason),
                'cancelled_by'        => $cancelledById,
                'cancelled_at'        => now(),
            ]);

            // Only money that actually came IN can go back out. Payments are
            // created `pending` at checkout and the one thing that has ever
            // marked one `paid` is a driver confirming COD cash — so writing
            // `refunded` unconditionally told the store it had returned money
            // it had never received. A pending payment on a cancelled order is
            // simply never collected; it stays pending, and cancelled orders
            // are excluded from every report figure anyway (countableOrders).
            if ($order->payment?->payment_status === 'paid') {
                $order->payment->update(['payment_status' => 'refunded']);
            }
        });
    }
}
