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
        'driver_id',
        'assigned_by',
        'assigned_at',
        'picked_up_at',
        'delivered_at',
        'delivery_note',
        'failed_attempts',
        'cash_collected_at',
        // Written as one array by DeliveryProofService::put(). Left out of
        // $fillable they are dropped by mass assignment IN SILENCE — the
        // delivery completes and the proof simply is not there.
        'proof_disk',
        'proof_path',
        'proof_mime',
        'proof_size',
        'proof_captured_at',
    ];

    protected function casts(): array
    {
        return [
            'order_date'        => 'datetime',
            'cancelled_at'      => 'datetime',
            'assigned_at'       => 'datetime',
            'picked_up_at'      => 'datetime',
            'delivered_at'      => 'datetime',
            'cash_collected_at' => 'datetime',
            'proof_captured_at' => 'datetime',
            'failed_attempts'   => 'integer',
        ];
    }

    /**
     * Both are appended so the CLIENT never has to re-derive the cancel rule.
     * It differs for custom orders, and a copy of that logic in the app would
     * drift from the server's — offering a Cancel button the API then refuses.
     */
    protected $appends = ['has_custom_items', 'can_cancel', 'driver_contact', 'proof_url'];

    /** True while the photo taken at handover is still on disk. */
    public function hasProof(): bool
    {
        return filled($this->proof_path);
    }

    /**
     * Where the customer's app fetches the delivery photo.
     *
     * The app ROUTE, never a storage path — the gate lives in
     * DeliveryProofController and a storage URL would route around it, as well
     * as being unservable on Cloud. Null once the 12-month prune has removed
     * the file, at which point `proof_captured_at` still records that a photo
     * was taken.
     */
    public function getProofUrlAttribute(): ?string
    {
        return $this->hasProof()
            ? route('api.orders.proof', ['order' => $this->getKey()])
            : null;
    }

    /**
     * Operational fields the customer has no business receiving.
     *
     * These serialize into every `GET /api/orders` response otherwise — which
     * admin assigned the order, and when a driver handed cash over. Hiding
     * affects toArray()/toJson() only, so the admin panel and the services
     * still read `$order->driver_id` exactly as before.
     *
     * `failed_attempts`, `delivery_note`, `picked_up_at` and `delivered_at`
     * are deliberately NOT hidden — those are the customer's own delivery,
     * and the app uses them to date the tracker and explain a missed attempt.
     */
    protected $hidden = [
        'driver_id', 'assigned_by', 'cash_collected_at',
        // Where the photo physically lives is nobody's business but the
        // server's — the customer gets `proof_url`, which goes through the
        // gate. Handing out disk and path would be handing out a way around it.
        'proof_disk', 'proof_path',
    ];

    /**
     * Who is bringing this order, for the customer's screen.
     *
     * Name and phone only, and only once the order has actually left the store
     * — before that the assignment can still change, and naming a driver who
     * then gets swapped is worse than naming nobody. A pickup never has one.
     *
     * The phone is the point: the single most useful thing a customer can do
     * when a van is outside is answer the door, and the second is call. This
     * is the half of the admin's flood that is not order updates — "where is
     * my order" messages that nobody at the store can answer any better than
     * the person holding it.
     */
    public function getDriverContactAttribute(): ?array
    {
        if (! in_array($this->status, ['shipped', 'completed'], true)) {
            return null;
        }

        $driver = $this->relationLoaded('driver') ? $this->getRelation('driver') : $this->driver;

        if (! $driver) {
            return null;
        }

        return [
            'name'  => $driver->name,
            'phone' => $driver->phone,
        ];
    }

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

    /** Who is carrying this delivery right now. Null until an admin assigns. */
    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Can a driver be put on this order at all?
     *
     * Pickups are handed over at the counter and never assigned. A cancelled or
     * completed order has nowhere left to go, and assigning one would put a
     * finished job back on somebody's list.
     */
    public function isAssignable(): bool
    {
        return $this->order_type === 'delivery'
            && in_array($this->status, ['pending', 'processing', 'shipped'], true);
    }

    /**
     * The driver has the goods and has not yet handed them over.
     *
     * Read off picked_up_at rather than the status, because an admin using the
     * override path can move an order to `shipped` without any driver ever
     * having touched it.
     */
    public function isOutForDelivery(): bool
    {
        return $this->picked_up_at !== null && $this->delivered_at === null;
    }

    /** Cash on delivery — the one payment method a driver has to collect. */
    public function isCashOnDelivery(): bool
    {
        return $this->payment?->payment_method === 'cod';
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