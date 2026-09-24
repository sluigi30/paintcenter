<?php

namespace App\Models;

use App\Services\TintRecipe;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'custom_hex',         // a tint recipe's predicted colour
        'custom_color_name',  // the customer's own label, optional
        'tint_fee',           // display copy of the mixing fee; see TintRecipe::fee()
        'mix_recipe',         // set => a tint-recipe mix: [{tint_color_id, name, hex, ml}] per can
    ];

    protected $casts = [
        'tint_fee' => 'float',
        'mix_recipe' => 'array',
    ];

    /**
     * What the customer pays per can — base price plus the tint. A tint recipe
     * pays the CURRENT mixing fee; see TintRecipe::fee().
     */
    public function getUnitPriceAttribute(): float
    {
        $fee = $this->mix_recipe !== null ? TintRecipe::fee() : 0.0;

        return (float) $this->variant->price + $fee;
    }

    public function getIsCustomAttribute(): bool
    {
        return $this->custom_hex !== null;
    }

    /**
     * A tint-recipe mix: ONE line, the base can with colorant added.
     */
    public function getIsRecipeAttribute(): bool
    {
        return $this->mix_recipe !== null;
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
