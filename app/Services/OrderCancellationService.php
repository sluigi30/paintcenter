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

    public static function canCancel(Order $order): bool
    {
        return in_array($order->status, self::CANCELLABLE_STATUSES, true);
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

            $order->payment?->update(['payment_status' => 'refunded']);
        });
    }
}
