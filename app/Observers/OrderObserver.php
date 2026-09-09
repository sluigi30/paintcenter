<?php

namespace App\Observers;

use App\Jobs\SendOrderSms;
use App\Models\Order;
use App\Services\OrderMessageService;
use App\Services\SmsService;

class OrderObserver
{
    /**
     * Milestones worth a text. Intermediate churn (e.g. pending) is left to the
     * in-app thread so the customer's SIM isn't peppered with every save.
     */
    private const SMS_STATUSES = ['processing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled'];

    /**
     * Announce status changes to the customer.
     *
     * Lives on the model rather than in the Filament page so every path that
     * moves an order — the admin edit form, a table action, or the customer
     * cancelling from the mobile app — notifies the customer the same way, over
     * both channels: the in-app message thread and (for milestones) SMS.
     */
    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        OrderMessageService::statusChanged($order);

        if (in_array($order->status, self::SMS_STATUSES, true) && $order->user?->phone) {
            SendOrderSms::dispatch(
                $order->id,
                $order->user->phone,
                SmsService::orderStatusMessage($order),
            );
        }
    }
}
