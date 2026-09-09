<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Services\OrderCancellationService;
use App\Services\OrderMessageService;
use App\Jobs\SendOrderSms;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::with(['orderItems.product.brand', 'orderItems.variant', 'payment'])
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
            'cart_item_ids'    => 'sometimes|array|min:1',
            'cart_item_ids.*'  => 'integer',
        ]);

        // Partial checkout: the client may tick only some cart lines (Shopee/Lazada
        // style). Omitting cart_item_ids checks out the whole cart, as before.
        $selectedIds = $validated['cart_item_ids'] ?? null;

        $cartItems = CartItem::with(['product', 'variant'])
            ->where('user_id', $request->user()->id)
            ->when($selectedIds, fn ($q) => $q->whereIn('id', $selectedIds))
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'message' => $selectedIds
                    ? 'No items selected for checkout.'
                    : 'Cart is empty.',
            ], 422);
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

                // unit_price is the ALL-IN price per can: the base plus the
                // tint. tint_fee is carried alongside only so the breakdown
                // can be shown — adding the two again would double-charge.
                $tintFee       = (float) $cartItem->tint_fee;
                $unitPrice     = $variant->price + $tintFee;
                $subtotal      = $unitPrice * $quantity;
                $totalAmount  += $subtotal;

                $orderItems[] = [
                    'variant'           => $variant,
                    'quantity'          => $quantity,
                    'unit_price'        => $unitPrice,
                    'subtotal'          => $subtotal,
                    'custom_hex'        => $cartItem->custom_hex,
                    'custom_color_name' => $cartItem->custom_color_name,
                    'tint_fee'          => $tintFee,
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
                    // Snapshotted like size_volume and unit_price: the variant
                    // can later be renamed, re-priced or archived, and this
                    // order must still show what was actually bought.
                    'custom_hex'         => $item['custom_hex'],
                    'custom_color_name'  => $item['custom_color_name'],
                    'tint_fee'           => $item['tint_fee'],
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

            // Only the lines that were actually ordered leave the cart — unticked
            // items stay put for the next order
            CartItem::where('user_id', $request->user()->id)
                ->whereIn('id', $cartItems->pluck('id'))
                ->delete();

            DB::commit();

            // Post the order summary into the customer's message thread. Also
            // opens a thread the admin can reply in — customers who have never
            // messaged are otherwise unreachable from the admin inbox.
            OrderMessageService::orderPlaced($order);

            $phone = $request->user()->phone;

            if ($phone) {
                // Identify the order by the date it was placed, never its id —
                // ids are a shared auto-increment (see constants/orders.js).
                $smsMessage = SmsService::orderPlacedMessage(
                    $request->user()->first_name,
                    ($order->order_date ?? $order->created_at)?->format('j M Y') ?? 'today',
                    $totalAmount
                );

                // Queued so checkout does not block on the phone/relay.
                SendOrderSms::dispatch($order->id, $phone, $smsMessage);
            }
            return response()->json([
                'message' => 'Order placed successfully.',
                'order'   => $order->load(['orderItems.product.brand', 'orderItems.variant', 'payment']),
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

        $order->load(['orderItems.product.brand', 'orderItems.variant', 'payment']);
        return response()->json($order);
    }

    public function cancel(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $order->loadMissing('orderItems');

        // Custom orders close to cancellation a status earlier — mixing
        // happens during `processing` and a tinted can cannot be resold.
        if (! OrderCancellationService::canCustomerCancel($order)) {
            return response()->json([
                'message' => $order->has_custom_items
                    ? 'This order is being custom mixed and can no longer be cancelled.'
                    : 'Order cannot be cancelled.',
            ], 422);
        }

        // A reason is required. The client offers Order::CUSTOMER_CANCEL_REASONS
        // as presets plus a free-text "Other", so any string is acceptable here.
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            OrderCancellationService::cancel($order, $validated['reason'], $request->user()->id);

            return response()->json(['message' => 'Order cancelled successfully.']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Cancellation failed.'], 500);
        }
    }
}