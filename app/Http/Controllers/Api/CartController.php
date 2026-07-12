<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function summary(Request $request)
    {
        $items = CartItem::with(['product', 'variant'])
            ->where('user_id', $request->user()->id)
            ->get()
            ->map(fn ($item) => [
                'cart_item_id'       => $item->id,
                'product_id'         => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'name'               => $item->product->description,
                'size_volume'        => $item->variant->size_volume,
                'price'              => $item->variant->price,
                'quantity'           => $item->quantity,
                'subtotal'           => $item->variant->price * $item->quantity,
                'hex_code'           => $item->product->hex_code,
                'image'              => $item->product->image,
                'available_stock'    => $item->variant->stock,
            ]);

        $total     = $items->sum('subtotal');
        $itemCount = $items->sum('quantity');

        return response()->json([
            'items'      => $items,
            'item_count' => $itemCount,
            'total'      => $total,
        ]);
    }

    public function add(Request $request)
    {
        $request->validate([
            'product_variant_id' => 'required|exists:product_variants,id',
            'quantity'           => 'required|integer|min:1',
        ]);

        $user    = auth()->user();
        $variant = ProductVariant::with('product')->findOrFail($request->product_variant_id);

        if ($variant->is_archived || $variant->product->is_archived) {
            return response()->json(['message' => 'Product not available.'], 422);
        }

        // The same size may already be in the cart — cap the merged quantity
        $existing = CartItem::where('user_id', $user->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $newQuantity = ($existing?->quantity ?? 0) + (int) $request->quantity;

        if ($variant->stock < $newQuantity) {
            return response()->json([
                'message' => "Only {$variant->stock} units of {$variant->size_volume} available in stock.",
            ], 422);
        }

        CartItem::updateOrCreate(
            [
                'user_id'            => $user->id,
                'product_variant_id' => $variant->id,
            ],
            [
                'product_id' => $variant->product_id,
                'quantity'   => $newQuantity,
            ]
        );

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
