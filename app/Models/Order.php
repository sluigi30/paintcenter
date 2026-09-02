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