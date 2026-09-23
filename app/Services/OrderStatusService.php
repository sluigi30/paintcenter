<?php

namespace App\Services;

use App\Models\Order;

/**
 * The only place an order's status is written.
 *
 * This logic used to live inline in OrderResource's Advance / Move Back
 * actions, which was fine while the admin panel was the only thing that moved
 * an order. It is not any more: a driver marking a delivery picked up or
 * delivered is a THIRD concurrent actor on the same row, and a future driver
 * app would be a fourth. A copy of the concurrency guard per caller is a copy
 * that drifts.
 *
 * Same reasoning as OrderCancellationService — one path, so the admin's press
 * and the driver's press cannot come to mean different things.
 *
 * Deliberately knows nothing about roles. It moves an order along
 * Order::STATUS_FLOW_BY_TYPE and stops. WHO is allowed to press the button is
 * DeliveryService's question (for a driver) or the resource's (for an admin);
 * mixing the two here is how you end up unable to let an admin override.
 */
class OrderStatusService
{
    /** Move one step forward along this order's own flow. */
    public static function advance(Order $order): StatusChange
    {
        return static::moveTo($order, $order->nextStatus());
    }

    /**
     * Move one step back.
     *
     * One step and no further, so correcting a slip cannot turn into rewriting
     * an order's history. The customer is messaged again — they were already
     * told the wrong thing, and silence would leave them with it.
     */
    public static function revert(Order $order): StatusChange
    {
        return static::moveTo($order, $order->previousStatus());
    }

    /**
     * Write the status, unless somebody got there first.
     *
     * The guard re-reads the row rather than trusting the button that was
     * rendered: the orders table polls every 30s, and two admins — or an admin
     * and a driver — can be holding the same row.
     *
     * It is an optimistic check, not a lock, and that is on purpose. Making it
     * airtight means a transaction around the write, and OrderObserver's side
     * effects run INSIDE that write: the customer's message is posted and
     * SendOrderSms is dispatched. Every queue connection in config/queue.php
     * has `after_commit => false`, so a rollback would leave a text already on
     * its way about a status change that never happened. The residual race here
     * is a millisecond wide and its worst outcome is one extra step, which Move
     * Back undoes; a phantom SMS cannot be undone at all.
     */
    protected static function moveTo(Order $order, ?string $target): StatusChange
    {
        if ($target === null) {
            return StatusChange::noStep();
        }

        $current = $order->status;

        if ($order->fresh()?->status !== $current) {
            return StatusChange::conflict();
        }

        // OrderObserver announces the change to the customer, over the message
        // thread and (for milestones) SMS. Must stay a model update — a query
        // builder update fires no Eloquent events, so the customer would never
        // be told.
        $order->update(['status' => $target]);

        return StatusChange::moved($current, $target);
    }
}
