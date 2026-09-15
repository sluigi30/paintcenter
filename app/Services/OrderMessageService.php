<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Order;
use App\Models\User;

/**
 * Automated order updates posted into the customer ↔ store message thread.
 *
 * These are plain messages sent from the active admin account, so they land in
 * the normal thread and an admin can simply reply — and, importantly, they open
 * a thread for customers who have never messaged first.
 *
 * SMS: the store has no gateway subscription yet. When one is added, send it
 * from the marked spot in post() — every order update already funnels through
 * there, so SMS and in-app text stay in sync by construction.
 */
class OrderMessageService
{
    public static function orderPlaced(Order $order): void
    {
        $order->loadMissing(['orderItems.product', 'orderItems.variant', 'payment']);

        $lines = [
            'Order received — ' . self::peso($order->total_amount),
            'Placed ' . self::placedAt($order),
        ];

        $lines = array_merge($lines, self::itemLines($order));

        $lines[] = $order->order_type === 'delivery'
            ? 'Delivery to: ' . ($order->shipping_address ?: 'address on file')
            : 'For pickup at NCM Paint Center, Balanga, Bataan';

        if ($method = $order->payment?->payment_method) {
            $lines[] = 'Payment: ' . self::paymentLabel($method);
        }

        $lines[] = "\nWe'll confirm your order shortly. Reply here if you need to change anything.";

        self::post($order, implode("\n", $lines));
    }

    public static function statusChanged(Order $order): void
    {
        // Orders are referred to by when they were placed, not by id — the ids
        // are a shared auto-increment, so a first-time customer seeing "#147"
        // reasonably wonders what happened to their other 146 orders.
        $ref = 'Your order from ' . self::placedAt($order);

        $text = match ($order->status) {
            'processing'       => "{$ref} has been confirmed and is now being prepared.",
            'shipped'          => "{$ref} is on its way to you.",
            'ready_for_pickup' => "{$ref} is ready for pickup at NCM Paint Center, Balanga, Bataan.",
            'completed'        => "{$ref} is complete. Thank you for shopping with NCM Paint Center!",
            'cancelled'        => self::cancellationText($order, $ref),
            // 'pending' is the state an order is created in — orderPlaced()
            // already covers it, so there is nothing to announce.
            default            => null,
        };

        if ($text !== null) {
            self::post($order, $text);
        }
    }

    /** Create the message from the store's account to the customer. */
    protected static function post(Order $order, string $content): void
    {
        $admin = User::activeAdmin();

        // No admin account to speak through — skip rather than fail the order.
        if (! $admin || ! $order->user_id) {
            return;
        }

        Message::create([
            'sender_id'   => $admin->id,
            'receiver_id' => $order->user_id,
            'content'     => $content,
            'timestamp'   => now(),
            'is_read'     => false,
        ]);

        // TODO(sms): once a Vonage subscription is active, mirror $content to
        // the customer's phone here via SmsService so both channels match.
    }

    /**
     * A cancellation reads very differently depending on who did it — an
     * apology from the store, or a confirmation of the customer's own action.
     *
     * The leading caps line is the emphasis: messages are plain text, so there
     * is no way to style the word itself without introducing typed messages.
     */
    protected static function cancellationText(Order $order, string $ref): string
    {
        $lines = ['ORDER CANCELLED'];

        $lines[] = $order->cancelledByCustomer()
            ? "{$ref} was cancelled at your request."
            : "{$ref} has been cancelled by NCM Paint Center.";

        // Spell out what was in it — by the time this lands the customer may
        // have several orders open and needs to know which goods are affected
        $lines[] = 'Cancelled items — ' . self::peso($order->total_amount);
        $lines  = array_merge($lines, self::itemLines($order));

        if ($reason = trim((string) $order->cancellation_reason)) {
            $lines[] = "Reason: {$reason}";
        }

        $lines[] = 'Any stock has been returned.';

        $lines[] = $order->cancelledByCustomer()
            ? 'Reply here if this was a mistake and we can help you reorder.'
            : "We're sorry for the inconvenience. Reply here and we'll sort it out with you.";

        return implode("\n", $lines);
    }

    /** One bullet per line item, e.g. "• 2x Anzahl Urethane, Red (1L)". */
    protected static function itemLines(Order $order): array
    {
        $order->loadMissing('orderItems.product');

        return $order->orderItems
            ->map(function ($item) {
                $name  = $item->product?->name ?: 'Item';
                // The shade is half of what was ordered now that one product
                // carries several — "2x BOYSEN Latex Colors (4L)" would not
                // tell the customer which can is coming.
                $color = $item->color_label !== '' ? ", {$item->color_label}" : '';
                $size  = $item->size_volume ? " ({$item->size_volume})" : '';

                return "• {$item->quantity}x {$name}{$color}{$size}";
            })
            ->all();
    }

    /**
     * How an order is identified to the customer. Date AND time, so a customer
     * who orders twice in one day can still tell the two apart.
     */
    protected static function placedAt(Order $order): string
    {
        return $order->created_at->format('M j, Y \a\t g:i A');
    }

    protected static function peso(float|string|null $amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }

    protected static function paymentLabel(string $method): string
    {
        return match ($method) {
            'cod'   => 'Cash on Delivery',
            'gcash' => 'GCash',
            'card'  => 'Credit/Debit Card',
            'cash'  => 'Cash on Pickup',
            default => ucfirst($method),
        };
    }
}
