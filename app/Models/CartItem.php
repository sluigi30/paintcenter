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
        'mix_group',          // set => this line is one ingredient of a mix
        'mix_role',           // 'base' | 'tint'
        'mix_liters',         // litres in ONE can; line total is quantity * this
    ];

    protected $casts = [
        'tint_fee' => 'float',
        'mix_liters' => 'float',
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

    /** Part of a customer-composed mix, rather than a can bought on its own. */
    public function getIsMixedAttribute(): bool
    {
        return $this->mix_group !== null;
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
