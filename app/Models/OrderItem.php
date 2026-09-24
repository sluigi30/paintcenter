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
        'color_code',         // snapshot of the ordered colour — the variant
        'color_name',         //   can be recoloured or archived later and the
        'hex_code',           //   order must still show what was handed over
        'size_volume',        // snapshot of the ordered size, like unit_price
        'custom_hex',         // snapshot: set => custom-tinted colour
        'custom_color_name',  // snapshot: the customer's own label
        'tint_fee',           // snapshot: a later price-list edit must not
        'mix_group',          // snapshot: ties this line to its recipe
        'mix_role',           // snapshot: 'base' | 'tint'
        'mix_liters',         // snapshot: litres in ONE can of this line
        'mix_recipe',         // snapshot: [{tint_color_id, name, hex, ml}] per can
        'quantity',           //   rewrite what was actually charged
        'unit_price',
        'subtotal',
    ];

    protected $casts = [
        'tint_fee' => 'float',
        'mix_liters' => 'float',
        'mix_recipe' => 'array',
    ];

    /**
     * Appended because orders are serialised straight to the app
     * ($order->load('orderItems...')), and the client needs to tell a custom
     * line from a ready-mixed one to badge it and to re-send the colour on
     * Buy Again. CartItem has the same accessor but does not append it —
     * CartController composes that payload by hand.
     */
    protected $appends = ['is_custom', 'is_mixed', 'is_recipe', 'color_label', 'display_color'];

    public function getIsCustomAttribute(): bool
    {
        return $this->custom_hex !== null;
    }

    /** Part of a customer-composed mix. Appended so the app can group the lines. */
    public function getIsMixedAttribute(): bool
    {
        return $this->mix_group !== null;
    }

    /** A tint-recipe mix: one base can with the colorant in mix_recipe. */
    public function getIsRecipeAttribute(): bool
    {
        return $this->mix_recipe !== null;
    }

    /** "Burnt Sienna (B-1408)" for a ready-mixed line, the customer's label for a custom one. */
    public function getColorLabelAttribute(): string
    {
        if ($this->is_custom) {
            return $this->custom_color_name ?: 'Custom colour';
        }

        if ($this->color_name && $this->color_code) {
            return "{$this->color_name} ({$this->color_code})";
        }

        return (string) ($this->color_name ?: $this->color_code ?: '');
    }

    /** The swatch hex, whichever kind of line this is. Null when there is no colour. */
    public function getDisplayColorAttribute(): ?string
    {
        return $this->is_custom ? $this->custom_hex : $this->hex_code;
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
