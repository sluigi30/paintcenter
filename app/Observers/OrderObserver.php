<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\OrderMessageService;

class OrderObserver
{
    /**
     * Announce status changes in the customer's message thread.
     *
     * Lives on the model rather than in the Filament page so every path that
     * moves an order — the admin edit form, a table action, or the customer
     * cancelling from the mobile app — notifies the customer the same way.
     */
    public function updated(Order $order): void
    {
        if ($order->wasChanged('status')) {
            OrderMessageService::statusChanged($order);
        }
    }
}
