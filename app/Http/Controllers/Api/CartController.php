<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Services\TintRecipe;
use Illuminate\Http\Request;

/**
 * The cart. Two kinds of line:
 *
 *   a can off the shelf     /cart/add — a catalogue variant, as it comes
 *   a tint recipe           /cart/mix (MixController) — a mixing-base can with
 *                           colorant in mix_recipe, priced base + mixing fee
 */
class CartController extends Controller
{
    public function summary(Request $request)
    {
        $items = CartItem::with(['product', 'variant'])
            ->where('user_id', $request->user()->id)
            ->get()
            ->map(function ($item) {
                $isRecipe = $item->mix_recipe !== null;

                // price stays the ALL-IN unit price the client multiplies by
                // quantity; base_price + tint_fee break it down so the mixing
                // fee is shown rather than silently folded in. A recipe pays
                // the CURRENT fee, as every line pays the current price.
                $basePrice = (float) $item->variant->price;
                $tintFee = $isRecipe ? TintRecipe::fee() : 0.0;
                $unitPrice = $basePrice + $tintFee;

                return [
                    'cart_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'name' => $item->product->name,
                    'size_volume' => $item->variant->size_volume,
                    'price' => $unitPrice,
                    'base_price' => $basePrice,
                    'tint_fee' => $tintFee,
                    'quantity' => $item->quantity,
                    'subtotal' => $unitPrice * $item->quantity,

                    // A recipe's colour is served through the SAME fields a
                    // ready-mixed can uses, so every swatch in the app renders
                    // the predicted mix with no change. is_custom drives the
                    // badge and the narrower cancellation window.
                    'hex_code' => $isRecipe ? $item->custom_hex : $item->variant->hex_code,
                    'color_name' => $isRecipe ? $item->custom_color_name : ($item->variant->color_name ?: null),
                    'color_code' => $isRecipe ? null : ($item->variant->color_code ?: null),
                    'color_label' => $isRecipe
                        ? ($item->custom_color_name ?: 'Custom colour')
                        : ($item->variant->color_label ?: null),
                    'is_custom' => $isRecipe,
                    'custom_hex' => $item->custom_hex,
                    'custom_color_name' => $item->custom_color_name,

                    // This base can, with these colorants in it (ml per can).
                    'is_recipe' => $isRecipe,
                    'mix_recipe' => $item->mix_recipe,

                    'image' => $item->product->image,
                    'available_stock' => $item->variant->stock,
                ];
            });

        return response()->json([
            'items' => $items,
            'item_count' => $items->sum('quantity'),
            'total' => $items->sum('subtotal'),

            // Checkout and the cancel dialog both need to know before the
            // customer commits: a tinted can cannot be un-tinted or resold.
            'has_custom' => $items->contains('is_custom', true),
        ]);
    }

    public function add(Request $request)
    {
        $request->validate([
            'product_variant_id' => 'required|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
        ]);

        // A colour of the customer's own is a tint recipe, made on the mixing
        // bench and posted to /cart/mix. Silently dropping a colour sent here
        // would sell a can in a colour they did not choose.
        if ($request->filled('custom_hex')) {
            return response()->json([
                'message' => 'Custom colours are mixed on the mixing screen. Please update the app to mix this colour.',
            ], 422);
        }

        $user = auth()->user();
        $variant = ProductVariant::with('product')->findOrFail($request->product_variant_id);
        $product = $variant->product;

        if ($variant->is_archived || $product->is_archived) {
            return response()->json(['message' => 'Product not available.'], 422);
        }

        // Base is sold mixed, through /cart/mix, never as a plain can.
        if ($product->is_mixing_base) {
            return response()->json([
                'message' => 'This base is only sold mixed. Create your colour on the mixing screen.',
            ], 422);
        }

        // One line per variant. Recipe lines are excluded outright: an ordinary
        // can of the same base must never merge INTO somebody's mix.
        $existing = CartItem::where('user_id', $user->id)
            ->where('product_variant_id', $variant->id)
            ->whereNull('mix_recipe')
            ->first();

        $newQuantity = ($existing?->quantity ?? 0) + (int) $request->quantity;

        if ($variant->stock < $newQuantity) {
            return response()->json([
                'message' => "Only {$variant->stock} units of {$variant->size_volume} available in stock.",
            ], 422);
        }

        if ($existing) {
            $existing->update(['quantity' => $newQuantity]);
        } else {
            CartItem::create([
                'user_id' => $user->id,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity' => $newQuantity,
                'tint_fee' => 0,
            ]);
        }

        return $this->summary($request);
    }

    /** Cans of one line. For a recipe, more cans of the SAME colour. */
    public function update(Request $request, CartItem $cartItem)
    {
        if ($cartItem->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        if ($cartItem->variant->stock < $validated['quantity']) {
            return response()->json([
                'message' => 'Insufficient stock.',
                'available_stock' => $cartItem->variant->stock,
            ], 422);
        }

        $cartItem->update(['quantity' => $validated['quantity']]);

        return $this->summary($request);
    }

    public function remove(Request $request, CartItem $cartItem)
    {
        if ($cartItem->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $cartItem->delete();

        return $this->summary($request);
    }

    public function clear(Request $request)
    {
        CartItem::where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Cart cleared.']);
    }
}
