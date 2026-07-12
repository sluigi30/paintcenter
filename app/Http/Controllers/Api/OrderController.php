<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::with(['orderItems.product', 'orderItems.variant', 'payment'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_type'       => 'required|in:delivery,pickup',
            'shipping_address' => 'required_if:order_type,delivery|nullable|string',
            'payment_method'   => 'required|in:cash,gcash,card,cod',
        ]);

        $cartItems = CartItem::with(['product', 'variant'])
            ->where('user_id', $request->user()->id)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'Cart is empty.'], 422);
        }

        DB::beginTransaction();

        try {
            $totalAmount = 0;
            $orderItems  = [];

            foreach ($cartItems as $cartItem) {
                // Stock is checked and deducted per VARIANT (size)
                $variant  = ProductVariant::lockForUpdate()->findOrFail($cartItem->product_variant_id);
                $quantity = $cartItem->quantity;

                if ($variant->stock < $quantity) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock for {$cartItem->product->description} ({$variant->size_volume}).",
                    ], 422);
                }

                $subtotal      = $variant->price * $quantity;
                $totalAmount  += $subtotal;

                $orderItems[] = [
                    'variant'    => $variant,
                    'quantity'   => $quantity,
                    'unit_price' => $variant->price,
                    'subtotal'   => $subtotal,
                ];
            }

            $order = Order::create([
                'user_id'          => $request->user()->id,
                'order_date'       => now(),
                'order_type'       => $validated['order_type'],
                'status'           => 'pending',
                'total_amount'     => $totalAmount,
                'shipping_address' => $validated['shipping_address'] ?? null,
            ]);

            foreach ($orderItems as $item) {
                OrderItem::create([
                    'order_id'           => $order->id,
                    'product_id'         => $item['variant']->product_id,
                    'product_variant_id' => $item['variant']->id,
                    'size_volume'        => $item['variant']->size_volume,
                    'quantity'           => $item['quantity'],
                    'unit_price'         => $item['unit_price'],
                    'subtotal'           => $item['subtotal'],
                ]);

                $item['variant']->decrement('stock', $item['quantity']);

                InventoryLog::create([
                    'product_id'         => $item['variant']->product_id,
                    'product_variant_id' => $item['variant']->id,
                    'action_name'        => 'order_placed',
                    'quantity_changed'   => -$item['quantity'],
                ]);
            }

            Payment::create([
                'order_id'       => $order->id,
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'pending',
                'payment_date'   => null,
            ]);

            CartItem::where('user_id', $request->user()->id)->delete();

            DB::commit();

            $smsService = new SmsService();
            $phone = $request->user()->phone;

            if ($phone) {
                $smsMessage = SmsService::orderPlacedMessage(
                    $request->user()->first_name,
                    $order->id,
                    $totalAmount
                );

                $smsService->send($order->id, $phone, $smsMessage);
            }
            return response()->json([
                'message' => 'Order placed successfully.',
                'order'   => $order->load(['orderItems.product', 'orderItems.variant', 'payment']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Order failed. Please try again.'], 500);
        }
    }

    public function show(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $order->load(['orderItems.product', 'orderItems.variant', 'payment']);
        return response()->json($order);
    }

    public function cancel(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (!in_array($order->status, ['pending', 'processing'])) {
            return response()->json(['message' => 'Order cannot be cancelled.'], 422);
        }

        DB::beginTransaction();

        try {
            foreach ($order->orderItems as $item) {
                // Restore stock on the exact size that was ordered
                $item->variant?->increment('stock', $item->quantity);

                InventoryLog::create([
                    'product_id'         => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'action_name'        => 'order_cancelled',
                    'quantity_changed'   => $item->quantity,
                ]);
            }

            $order->update(['status' => 'cancelled']);
            $order->payment->update(['payment_status' => 'refunded']);

            DB::commit();

            return response()->json(['message' => 'Order cancelled successfully.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Cancellation failed.'], 500);
        }
    }
}