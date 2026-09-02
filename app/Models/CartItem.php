<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'custom_hex',         // set => this line is a custom-tinted colour
        'custom_color_name',  // the customer's own label, optional
        'tint_fee',           // copied from the variant at add time
    ];

    protected $casts = [
        'tint_fee' => 'float',
    ];

    /** What the customer pays per can — base price plus the tint. */
    public function getUnitPriceAttribute(): float
    {
        return (float) $this->variant->price + (float) $this->tint_fee;
    }

    public function getIsCustomAttribute(): bool
    {
        return $this->custom_hex !== null;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
