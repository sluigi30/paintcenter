<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Everything a driver does to an order, and the admin's side of assigning one.
 *
 * Shared by the /admin panel (assign, reassign) and the /driver panel (pick up,
 * deliver, failed attempt), so the two cannot come to mean different things —
 * the same arrangement as OrderCancellationService.
 *
 * It never writes `status` itself. Anything that moves an order along its flow
 * goes through OrderStatusService, which owns the concurrency guard and is the
 * one place OrderObserver's customer announcement is triggered from.
 *
 * Deliberately not an event with listeners. There is exactly one publisher for
 * each of these — an admin's button or a driver's button — so an event would
 * buy decoupling nothing needs while spreading "what happens when you assign"
 * across five files. There is also a hazard this codebase already wrote down in
 * AdminOrderAlertService: model events fire inside the transaction, and every
 * queue connection is configured `after_commit => false`. See DELIVERY_ROLE.md.
 */
class DeliveryService
{
    /**
     * How many times a driver may report a failed attempt before the decision
     * goes back to the store.
     *
     * Without a ceiling an undeliverable order sits `shipped` forever: it is
     * off every admin work list (they think it is out on the van) and on the
     * driver's list for good. At the cap the driver's button is withdrawn and
     * an admin has to either cancel it — through OrderCancellationService, so
     * stock returns and the customer is told why — or reassign it.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Preset reasons a delivery came back. UI suggestions only — the driver can
     * always type their own under "Other", and delivery_note is never
     * constrained to this list. Same arrangement as Order::ADMIN_CANCEL_REASONS.
     *
     * "Customer could not pay" is the COD escape hatch: cash on delivery means
     * no cash, no handover, so a customer who cannot pay is a failed attempt
     * rather than a delivery the driver has to lie about to close.
     */
    public const FAILURE_REASONS = [
        'Nobody home',
        'Customer could not pay',
        'Wrong or incomplete address',
        'Customer refused the order',
        "Couldn't access the location",
        'Customer asked to reschedule',
    ];

    // ---------------------------------------------------------------
    // Admin side
    // ---------------------------------------------------------------

    /**
     * Put a driver on a delivery, or move it from one driver to another.
     *
     * The activity log gets an explicit entry as well as the automatic diff
     * TracksActivity writes. The diff alone records `driver_id: 3 → 7`, which
     * is unreadable in the feed exactly when someone is trying to work out who
     * had the order.
     */
    public static function assign(Order $order, User $driver, ?User $actor = null): void
    {
        if (! $order->isAssignable()) {
            throw new DomainException('This order cannot be assigned to a driver.');
        }

        if (! $driver->isDriver() || $driver->is_archived) {
            throw new DomainException('That account is not an active driver.');
        }

        $previous = $order->driver;

        if ($previous && $previous->is($driver)) {
            return;
        }

        // An order already out on a van is coming BACK to the store before
        // anyone else can carry it — that is exactly what the previous driver
        // is told below ("please return the items"). So a reassignment at
        // `shipped` returns the order to `processing` and wipes the delivery
        // facts with it.
        //
        // Without this the new driver inherits a spent attempt counter (no
        // Couldn't Deliver button from the moment they are assigned) and a
        // picked_up_at they never earned, which lands the order in their Out
        // for Delivery tab offering only "Delivered" — for goods still sitting
        // on a shelf at the shop. Reassignment simply did not work.
        if ($order->status === 'shipped') {
            // Through OrderStatusService so the customer is told their order
            // has come back to the shop, rather than silently rewriting a
            // status behind their tracker.
            OrderStatusService::revert($order);

            $order->update([
                'failed_attempts' => 0,
                'picked_up_at'    => null,
            ]);
            // delivery_note is deliberately KEPT: "Nobody home" is the most
            // useful thing the next driver can know before setting off.
        }

        $order->update([
            'driver_id'   => $driver->id,
            'assigned_by' => $actor?->id ?? auth()->id(),
            'assigned_at' => now(),
        ]);

        ActivityLog::log(
            'order.assigned',
            $order,
            $previous
                ? "Reassigned from {$previous->name} to {$driver->name}"
                : "Assigned to {$driver->name}",
        );

        static::notifyDriver(
            $driver,
            'New delivery assigned',
            static::assignmentBody($order),
            'heroicon-o-truck',
        );

        // The list is scoped on driver_id, so a reassigned order simply
        // VANISHES from the previous driver's screen — while they may still
        // have the cans in the van. Tell them.
        if ($previous) {
            static::notifyDriver(
                $previous,
                'Delivery reassigned',
                'The order placed <strong>' . static::placedAt($order)
                    . '</strong> has been passed to someone else. Please return the items to the store.',
                'heroicon-o-arrow-uturn-left',
                'warning',
            );
        }
    }

    // ---------------------------------------------------------------
    // Driver side
    // ---------------------------------------------------------------

    /**
     * The driver has the goods. processing → shipped.
     */
    public static function pickUp(Order $order, User $driver): StatusChange
    {
        static::assertOwnedBy($order, $driver);

        if ($order->status !== 'processing') {
            throw new DomainException('This order is not ready to be picked up.');
        }

        $change = OrderStatusService::advance($order);

        if ($change->succeeded()) {
            $order->update(['picked_up_at' => now()]);
        }

        return $change;
    }

    /**
     * Handed over. shipped → completed.
     *
     * A COD order cannot pass through here without the cash being confirmed.
     * That is not a formality: payments rows are created `pending` at checkout
     * and nothing else in the system ever marks a COD one paid, so an
     * unconfirmed delivery leaves the store unable to say who is holding the
     * money. `cash_collected_at` is stamped alongside the payment because
     * payment_status alone cannot answer WHICH driver took it.
     *
     * A customer who cannot pay is not this path — it is a failed attempt. COD
     * means cash on delivery: no cash, no handover, and the goods come back.
     */
    public static function deliver(
        Order $order,
        User $driver,
        bool $cashCollected = false,
        ?UploadedFile $proof = null,
    ): StatusChange {
        static::assertOwnedBy($order, $driver);

        if ($order->status !== 'shipped') {
            throw new DomainException('This order is not out for delivery.');
        }

        $isCod = $order->isCashOnDelivery();

        if ($isCod && ! $cashCollected) {
            throw new DomainException('Confirm the cash was collected before marking this delivered.');
        }

        // A photo is required of the DRIVER, on every handover. The escape
        // hatch for a dead camera is deliberately not here: it is the admin's
        // Advance override, which does not come through this service at all, so
        // a store that can vouch for a delivery nobody photographed can still
        // complete the order — and ActivityLog records which admin did it. A
        // "skip" button here is one the drivers would simply learn to press.
        if (! $proof) {
            throw new DomainException('Take a photo of the delivery before marking it delivered.');
        }

        // ORDER MATTERS, and not merely "before the transaction".
        //
        // The file is written first, then attached while the order is still
        // `shipped`, and only THEN does the status move. Advancing first would
        // fire OrderObserver — the customer's message and the SMS — and any
        // failure after that leaves exactly the state this feature exists to
        // prevent: an order marked completed, a customer already told, and no
        // proof. Attaching first means the worst case is a `shipped` order
        // carrying an unused photo: invisible, recoverable, and nothing false
        // has been said to anybody.
        $stored = DeliveryProofService::put($proof);

        try {
            $order->update($stored);

            $change = OrderStatusService::advance($order);

            if (! $change->succeeded()) {
                // Somebody else moved it. The photo describes a handover this
                // call did not perform, so it does not belong on the row.
                $order->update([
                    'proof_disk'        => null,
                    'proof_path'        => null,
                    'proof_mime'        => null,
                    'proof_size'        => null,
                    'proof_captured_at' => null,
                ]);
                DeliveryProofService::discard($stored);

                return $change;
            }

            DB::transaction(function () use ($order, $isCod) {
                $order->update([
                    'delivered_at'      => now(),
                    'cash_collected_at' => $isCod ? now() : null,
                ]);

                if ($isCod && $order->payment) {
                    $order->payment->update([
                        'payment_status' => 'paid',
                        'payment_date'   => now(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            DeliveryProofService::discard($stored);

            throw $e;
        }

        ActivityLog::log(
            'order.delivered',
            $order,
            $isCod
                ? 'Delivered with photo — cash collected ' . static::peso($order->total_amount)
                : 'Delivered with photo',
        );

        return $change;
    }

    /**
     * Tried and could not hand it over.
     *
     * The order stays `shipped` and keeps picked_up_at: the goods DID leave the
     * store, and erasing that loses track of how long stock has been off the
     * shelf. No new status either — a failed attempt is a fact about the trip,
     * not a new place in the journey, and inventing one would ripple into the
     * customer's tracker, the SMS milestones and the mobile app's mirrored FLOW.
     */
    public static function recordFailedAttempt(Order $order, User $driver, string $reason): int
    {
        static::assertOwnedBy($order, $driver);

        if (! $order->isOutForDelivery()) {
            throw new DomainException('This order is not out for delivery.');
        }

        if ($order->failed_attempts >= self::MAX_ATTEMPTS) {
            throw new DomainException('This delivery has already reached the attempt limit.');
        }

        $attempts = $order->failed_attempts + 1;

        $order->update([
            'failed_attempts' => $attempts,
            'delivery_note'   => $reason,
        ]);

        ActivityLog::log(
            'order.delivery_failed',
            $order,
            "Attempt {$attempts} of " . self::MAX_ATTEMPTS . " failed — {$reason}",
        );

        // ORDER MATTERS: state, then the store, then the customer.
        //
        // The final attempt tells the customer "someone from the store will
        // contact you" — a promise only the admin alert can keep. When this ran
        // the other way round it broke exactly that way: the alert threw (an
        // unpinned cross-panel URL), and the customer was left holding a
        // promise from a store that had never been told. Raising the alert
        // first means a failure there aborts before any promise is made, and
        // the state is already safely written either way.
        if ($attempts >= self::MAX_ATTEMPTS) {
            AdminOrderAlertService::deliveryExhausted($order, $reason);
        }

        OrderMessageService::deliveryAttemptFailed($order, $reason, $attempts, self::MAX_ATTEMPTS);

        return $attempts;
    }

    /** Has this driver run out of attempts on this order? */
    public static function attemptsExhausted(Order $order): bool
    {
        return $order->failed_attempts >= self::MAX_ATTEMPTS;
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * A driver may only touch their own delivery.
     *
     * Throws rather than returning false because every caller has already
     * scoped its query to driver_id — reaching here means the request was
     * hand-made. The PANEL answers such a request with a 404 rather than a 403,
     * the same rule MessageAttachmentController follows: order ids are a plain
     * auto-increment, so a 403 on a row that exists is a difference anyone can
     * measure by counting.
     */
    protected static function assertOwnedBy(Order $order, User $driver): void
    {
        if ($order->driver_id === null || $order->driver_id !== $driver->id) {
            throw new DomainException('This delivery is not assigned to you.');
        }
    }

    protected static function notifyDriver(
        User $driver,
        string $title,
        string $body,
        string $icon,
        string $colour = 'success',
    ): void {
        $notification = Notification::make()
            ->title($title)
            ->icon($icon)
            ->body($body);

        $colour === 'warning' ? $notification->warning() : $notification->success();

        // notifyNow, not sendToDatabase — the latter queues silently and waits
        // on a worker nobody is running. Same rule as the stock alerts.
        $driver->notifyNow($notification->toDatabase());
    }

    /** Bodies render as sanitized HTML, so <strong> rather than markdown. */
    protected static function assignmentBody(Order $order): string
    {
        $customer = $order->user?->name ?: 'A customer';
        $payment  = $order->isCashOnDelivery()
            ? ' <strong>COD ' . static::peso($order->total_amount) . '</strong> to collect.'
            : '';

        return sprintf(
            'Order placed <strong>%s</strong> for %s.%s',
            e(static::placedAt($order)),
            e($customer),
            $payment,
        );
    }

    /** Orders are named by when they were placed, never by id. */
    protected static function placedAt(Order $order): string
    {
        return $order->created_at->format('M j, Y \a\t g:i A');
    }

    protected static function peso(float|string|null $amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }
}
