<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function summary(Request $request)
    {
        $items = CartItem::with('product')
            ->where('user_id', $request->user()->id)
            ->get()
            ->map(fn($item) => [
                'cart_item_id' => $item->id,
                'product_id'   => $item->product_id,
                'name'         => $item->product->description,
                'price'        => $item->product->price,
                'quantity'     => $item->quantity,
                'subtotal'     => $item->product->price * $item->quantity,
                'hex_code'     => $item->product->hex_code,
                'size_volume'  => $item->product->size_volume,
                'image'        => $item->product->image,
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
            'product_id'    => 'required|exists:products,id',
            'quantity'      => 'required|integer|min:1',
            'selected_size' => 'nullable|string|max:20',
        ]);

        $user    = auth()->user();
        $product = Product::findOrFail($request->product_id);

        if ($product->stock < $request->quantity) {
            return response()->json([
                'message' => "Only {$product->stock} units available in stock."
            ], 422);
        }

        CartItem::updateOrCreate(
            [
                'user_id'    => $user->id,
                'product_id' => $request->product_id,
            ],
            [
                'quantity'      => \DB::raw("quantity + {$request->quantity}"),
                'selected_size' => $request->selected_size,
            ]
        );

        // Reuse the existing summary() method instead of missing helpers
        return $this->summary($request);
    }
    public function update(Request $request, $productId)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($productId);

        if ($product->stock < $validated['quantity']) {
            return response()->json([
                'message'         => 'Insufficient stock.',
                'available_stock' => $product->stock,
            ], 422);
        }

        CartItem::where('user_id', $request->user()->id)
            ->where('product_id', $productId)
            ->update(['quantity' => $validated['quantity']]);

        return $this->summary($request);
    }

    public function remove(Request $request, $productId)
    {
        CartItem::where('user_id', $request->user()->id)
            ->where('product_id', $productId)
            ->delete();

        return $this->summary($request);
    }

    public function clear(Request $request)
    {
        CartItem::where('user_id', $request->user()->id)->delete();
        return response()->json(['message' => 'Cart cleared.']);
    }
}