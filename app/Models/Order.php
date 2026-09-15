<?php

namespace App\Models;

use App\Models\Concerns\TracksActivity;
use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(OrderObserver::class)]
class Order extends Model
{
    use HasFactory, TracksActivity;

    public function activityTitle(): string
    {
        return 'Order #' . $this->getKey();
    }


    protected $fillable = [
        'user_id',
        'order_date',
        'order_type',
        'status',
        'total_amount',
        'shipping_address',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'order_date'   => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Both are appended so the CLIENT never has to re-derive the cancel rule.
     * It differs for custom orders, and a copy of that logic in the app would
     * drift from the server's — offering a Cancel button the API then refuses.
     */
    protected $appends = ['has_custom_items', 'can_cancel'];

    /** True when any line is custom-tinted, i.e. mixed to a chosen colour. */
    public function getHasCustomItemsAttribute(): bool
    {
        return $this->orderItems->contains(fn ($item) => $item->custom_hex !== null);
    }

    public function getCanCancelAttribute(): bool
    {
        return \App\Services\OrderCancellationService::canCustomerCancel($this);
    }

    /**
     * Which payment methods each order type may use.
     *
     * Cash on Delivery only makes sense for a delivery, and paying cash at the
     * counter only for a pickup — but the app offered all four to both, so a
     * pickup could be placed as "Cash on Delivery" and never reconciled.
     *
     * Cash on Pickup is gone entirely (QA, 2026-09-14): a pickup order that
     * costs nothing to place and nothing to abandon is free to spam, and the
     * store was absorbing reserved stock for orders nobody came to collect.
     * Pickup must therefore be paid online. `cash` stays in the payments enum
     * so existing orders still read back — it is simply no longer offered.
     *
     * Enforced server-side in OrderController::store; the app hides the rows
     * that do not apply, which is presentation, not protection.
     */
    public const PAYMENT_METHODS_BY_TYPE = [
        'delivery' => ['cod', 'gcash', 'card'],
        'pickup'   => ['gcash', 'card'],
    ];

    /**
     * The order of statuses an order moves through, per type.
     *
     * The two types are genuinely different journeys: a delivery goes out on a
     * van, a pickup waits on a shelf. A dropdown listing every status let an
     * admin put a delivery into `ready_for_pickup` — and the app's tracker,
     * which has always been per-type (constants/orders.js FLOW), could not
     * find that status in the delivery flow and fell back to showing the order
     * as still "Order Placed". The store thought it was ready; the customer
     * saw an untouched order.
     *
     * So the flow lives here and the admin advances along it one step at a
     * time. Mirrored by FLOW in the mobile app — keep the two in step.
     *
     * `cancelled` is deliberately absent: it is not a step, it is an exit, and
     * it goes through OrderCancellationService so a reason is always captured
     * and stock always returns.
     */
    public const STATUS_FLOW_BY_TYPE = [
        'delivery' => ['pending', 'processing', 'shipped', 'completed'],
        'pickup'   => ['pending', 'processing', 'ready_for_pickup', 'completed'],
    ];

    /**
     * What the admin's button says it will do. Keyed by the status being moved
     * INTO, and worded as the action rather than the state — the admin is
     * recording something that happened, not setting a field.
     */
    public const STATUS_ADVANCE_LABELS = [
        'processing'       => 'Start Processing',
        'shipped'          => 'Out for Delivery',
        'ready_for_pickup' => 'Ready for Pickup',
        'completed'        => 'Mark Completed',
    ];

    /** The flow this order follows; unknown types are treated as delivery. */
    public function statusFlow(): array
    {
        return self::STATUS_FLOW_BY_TYPE[$this->order_type] ?? self::STATUS_FLOW_BY_TYPE['delivery'];
    }

    /**
     * The next status along, or null at the end of the flow — and for a
     * cancelled order, or one sitting on a status its own type does not use
     * (which older orders can be, from before the flow was enforced).
     */
    public function nextStatus(): ?string
    {
        if ($this->status === 'cancelled') {
            return null;
        }

        $flow  = $this->statusFlow();
        $index = array_search($this->status, $flow, true);

        return $index === false ? null : ($flow[$index + 1] ?? null);
    }

    /** The step before this one, for undoing a press that went too far. */
    public function previousStatus(): ?string
    {
        if ($this->status === 'cancelled') {
            return null;
        }

        $flow  = $this->statusFlow();
        $index = array_search($this->status, $flow, true);

        return ($index === false || $index < 1) ? null : $flow[$index - 1];
    }

    /** Human label for a status, falling back to a readable form. */
    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending'          => 'Pending',
            'processing'       => 'Processing',
            'shipped'          => 'Out for Delivery',
            'ready_for_pickup' => 'Ready for Pickup',
            'completed'        => 'Completed',
            'cancelled'        => 'Cancelled',
            default            => ucwords(str_replace('_', ' ', (string) $status)),
        };
    }

    /**
     * Preset cancellation reasons. These are UI suggestions only — both cancel
     * paths also accept free text typed under "Other", so the stored value is
     * never constrained to this list.
     */
    public const CUSTOMER_CANCEL_REASONS = [
        'Changed my mind',
        'Ordered the wrong item or size',
        'Found a better price elsewhere',
        'Taking too long to arrive',
        'Wrong delivery address',
    ];

    public const ADMIN_CANCEL_REASONS = [
        'Item out of stock',
        'Customer requested cancellation',
        'Unable to contact customer',
        'Payment not completed',
        'Outside our delivery area',
        'Duplicate order',
    ];

    /** True when the customer cancelled their own order, rather than the store. */
    public function cancelledByCustomer(): bool
    {
        return $this->cancelled_by !== null && $this->cancelled_by === $this->user_id;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function smsLogs()
    {
        return $this->hasMany(SmsLog::class);
    }
}