<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::with(['orderItems.product', 'payment'])
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

        $cartItems = CartItem::with('product')
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
            $product = Product::lockForUpdate()->findOrFail($cartItem->product_id);
            $item = ['quantity' => $cartItem->quantity];

                if ($product->stock < $item['quantity']) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock for {$product->description}.",
                    ], 422);
                }

                $subtotal      = $product->price * $item['quantity'];
                $totalAmount  += $subtotal;

                $orderItems[] = [
                    'product'   => $product,
                    'quantity'  => $item['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'  => $subtotal,
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
                    'order_id'   => $order->id,
                    'product_id' => $item['product']->id,
                    'quantity'   => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal'   => $item['subtotal'],
                ]);

                $item['product']->decrement('stock', $item['quantity']);

                InventoryLog::create([
                    'product_id'       => $item['product']->id,
                    'action_name'      => 'order_placed',
                    'quantity_changed' => -$item['quantity'],
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
                'order'   => $order->load(['orderItems.product', 'payment']),
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

        $order->load(['orderItems.product', 'payment']);
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
                $item->product->increment('stock', $item->quantity);

                InventoryLog::create([
                    'product_id'       => $item->product_id,
                    'action_name'      => 'order_cancelled',
                    'quantity_changed' => $item->quantity,
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