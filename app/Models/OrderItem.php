<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'size_volume',        // snapshot of the ordered size, like unit_price
        'custom_hex',         // snapshot: set => custom-tinted colour
        'custom_color_name',  // snapshot: the customer's own label
        'tint_fee',           // snapshot: a later price-list edit must not
        'quantity',           //   rewrite what was actually charged
        'unit_price',
        'subtotal',
    ];

    protected $casts = [
        'tint_fee' => 'float',
    ];

    /**
     * Appended because orders are serialised straight to the app
     * ($order->load('orderItems...')), and the client needs to tell a custom
     * line from a ready-mixed one to badge it and to re-send the colour on
     * Buy Again. CartItem has the same accessor but does not append it —
     * CartController composes that payload by hand.
     */
    protected $appends = ['is_custom'];

    public function getIsCustomAttribute(): bool
    {
        return $this->custom_hex !== null;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
