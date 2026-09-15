<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Services\ColorService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function summary(Request $request)
    {
        $items = CartItem::with(['product', 'variant'])
            ->where('user_id', $request->user()->id)
            ->get()
            ->map(function ($item) {
                $isCustom = $item->custom_hex !== null;

                // price stays the ALL-IN unit price the client already
                // multiplies by quantity; base_price + tint_fee break it down
                // so the fee can be shown rather than silently folded in.
                $basePrice = (float) $item->variant->price;
                $tintFee   = (float) $item->tint_fee;
                $unitPrice = $basePrice + $tintFee;

                return [
                    'cart_item_id'       => $item->id,
                    'product_id'         => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'name'               => $item->product->name,
                    'size_volume'        => $item->variant->size_volume,
                    'price'              => $unitPrice,
                    'base_price'         => $basePrice,
                    'tint_fee'           => $tintFee,
                    'quantity'           => $item->quantity,
                    'subtotal'           => $unitPrice * $item->quantity,

                    // A custom line's colour is served through the SAME fields
                    // a ready-mixed one uses, so every swatch already in the
                    // app renders it with no change. is_custom is what drives
                    // the badge and Buy Again.
                    'hex_code'           => $isCustom ? $item->custom_hex : $item->variant->hex_code,
                    'color_name'         => $isCustom ? $item->custom_color_name : ($item->variant->color_name ?: null),
                    'color_code'         => $isCustom ? null : ($item->variant->color_code ?: null),
                    'color_label'        => $isCustom
                        ? ($item->custom_color_name ?: 'Custom colour')
                        : ($item->variant->color_label ?: null),
                    'is_custom'          => $isCustom,
                    'custom_hex'         => $item->custom_hex,
                    'custom_color_name'  => $item->custom_color_name,

                    'image'              => $item->product->image,
                    'available_stock'    => $item->variant->stock,
                ];
            });

        return response()->json([
            'items'      => $items,
            'item_count' => $items->sum('quantity'),
            'total'      => $items->sum('subtotal'),

            // Checkout and the cancel dialog both need to know before the
            // customer commits: a tinted can cannot be un-tinted or resold.
            'has_custom' => $items->contains('is_custom', true),
        ]);
    }

    public function add(Request $request)
    {
        $request->validate([
            'product_variant_id' => 'required|exists:product_variants,id',
            'quantity'           => 'required|integer|min:1',
            'custom_hex'         => 'nullable|string|max:9',
            'custom_color_name'  => 'nullable|string|max:60',
        ]);

        $user    = auth()->user();
        $variant = ProductVariant::with('product')->findOrFail($request->product_variant_id);
        $product = $variant->product;

        if ($variant->is_archived || $product->is_archived) {
            return response()->json(['message' => 'Product not available.'], 422);
        }

        $customHex = ColorService::normalizeHex($request->input('custom_hex'));

        if ($product->is_custom_color) {
            if ($customHex === null) {
                return response()->json([
                    'message' => 'Choose a colour for this paint before adding it to the cart.',
                ], 422);
            }

            // Refuse here, not at the counter with the money already taken.
            if (! ColorService::isInGamut($customHex)) {
                return response()->json([
                    'message'         => 'That colour is brighter than paint can be mixed. The closest we can mix is shown instead.',
                    'nearest_mixable' => ColorService::clampToGamut($customHex),
                ], 422);
            }

            // Never trust the client's variant choice: a pale sage and a deep
            // burgundy cannot go into the same can, and the wrong base makes
            // paint that visibly is not the colour ordered. '' means the
            // product line has no base distinction, so anything fits.
            $required = ColorService::baseCodeFor($customHex);

            if ($variant->base_code !== '' && $variant->base_code !== $required) {
                $correct = ProductVariant::where('product_id', $product->id)
                    ->where('size_volume', $variant->size_volume)
                    ->where('base_code', $required)
                    ->where('is_archived', false)
                    ->first();

                return response()->json([
                    'message'            => $correct
                        ? 'That colour needs a different mix for this size. Please try again.'
                        : "This colour isn't available in {$variant->size_volume}.",
                    'product_variant_id' => $correct?->id,
                ], 422);
            }
        } elseif ($customHex !== null) {
            // A finished red can cannot be tinted. Silently dropping the hex
            // would sell the customer a colour they did not choose.
            return response()->json([
                'message' => 'This paint is sold in a fixed colour and cannot be custom mixed.',
            ], 422);
        }

        $tintFee = $customHex !== null ? (float) $variant->tint_fee : 0.0;

        // The merge key is (variant, colour) — NOT variant alone. Merging on
        // the variant would collapse two different custom colours of the same
        // base and size into one line: charged for two cans, delivered two of
        // the same colour.
        $existing = CartItem::where('user_id', $user->id)
            ->where('product_variant_id', $variant->id)
            ->when(
                $customHex !== null,
                fn ($q) => $q->where('custom_hex', $customHex),
                fn ($q) => $q->whereNull('custom_hex')
            )
            ->first();

        $newQuantity = ($existing?->quantity ?? 0) + (int) $request->quantity;

        if ($variant->stock < $newQuantity) {
            return response()->json([
                'message' => "Only {$variant->stock} units of {$variant->size_volume} available in stock.",
            ], 422);
        }

        if ($existing) {
            $existing->update([
                'quantity' => $newQuantity,
                'tint_fee' => $tintFee,
                // A re-add may carry a new label for the same colour; keep the
                // old one rather than blanking it when none is sent.
                'custom_color_name' => $request->input('custom_color_name') ?: $existing->custom_color_name,
            ]);
        } else {
            CartItem::create([
                'user_id'            => $user->id,
                'product_id'         => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity'           => $newQuantity,
                'custom_hex'         => $customHex,
                'custom_color_name'  => $request->input('custom_color_name') ?: null,
                'tint_fee'           => $tintFee,
            ]);
        }

        return $this->summary($request);
    }

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
                'message'         => 'Insufficient stock.',
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
