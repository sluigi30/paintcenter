<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendOrderSms;
use App\Models\CartItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Services\AdminOrderAlertService;
use App\Services\OrderCancellationService;
use App\Services\OrderMessageService;
use App\Services\SmsService;
use App\Services\TintRecipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        // `driver` is eager-loaded for the appended driver_contact — without
        // it the accessor fires a query per order on the list screen.
        $orders = Order::with(['orderItems.product.brand', 'orderItems.variant', 'payment', 'driver'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_type' => 'required|in:delivery,pickup',
            'shipping_address' => 'required_if:order_type,delivery|nullable|string',
            // Optional, and deliberately so. It is the most useful line on the
            // form for a provincial delivery, but a customer who cannot think
            // of a landmark must not be blocked from ordering over it.
            'delivery_landmark' => 'nullable|string|max:255',
            // The pin. Optional throughout — a customer may decline the
            // permission, and one ordering for a job site SHOULD decline.
            // `required_with` on the pair, so a half-sent coordinate (one
            // field lost to a flaky connection) is rejected rather than
            // stored as a pin somewhere on the equator.
            'delivery_lat'      => 'nullable|required_with:delivery_lng|numeric|between:-90,90',
            'delivery_lng'      => 'nullable|required_with:delivery_lat|numeric|between:-180,180',
            // Metres, as REPORTED by the fix. Never assumed, never promised.
            'location_accuracy' => 'nullable|integer|min:0|max:100000',
            // Which methods are allowed depends on the order type — see
            // Order::PAYMENT_METHODS_BY_TYPE. Validated against the list for
            // the type that was actually sent, so a client that hides the
            // wrong rows and one that does not both end up honest.
            'payment_method' => [
                'required',
                Rule::in(Order::PAYMENT_METHODS_BY_TYPE[$request->input('order_type')]
                    ?? array_merge(...array_values(Order::PAYMENT_METHODS_BY_TYPE))),
            ],
            'cart_item_ids' => 'sometimes|array|min:1',
            'cart_item_ids.*' => 'integer',
        ], [
            'payment_method.in' => $request->input('order_type') === 'pickup'
                ? 'Pickup orders are paid online. Please choose GCash or a card.'
                : 'That payment method is not available for delivery orders.',
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

        // A tint recipe is re-checked for what may have gone away since it was
        // carted: the base, and every colorant in it. Refused whole, cart left
        // intact, nothing substituted — the customer edits their mix. Step and
        // cap are NOT re-checked; see TintRecipe.
        foreach ($cartItems->whereNotNull('mix_recipe') as $recipeLine) {
            if ($error = TintRecipe::checkoutError($recipeLine)) {
                return response()->json([
                    'message' => $error,
                    'cart_item_id' => $recipeLine->id,
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            $totalAmount = 0;
            $orderItems = [];

            foreach ($cartItems as $cartItem) {
                // Stock is checked and deducted per VARIANT (size)
                $variant = ProductVariant::lockForUpdate()->findOrFail($cartItem->product_variant_id);
                $quantity = $cartItem->quantity;

                if ($variant->stock < $quantity) {
                    DB::rollBack();

                    return response()->json([
                        'message' => "Insufficient stock for {$variant->display_name}.",
                    ], 422);
                }

                // unit_price is the ALL-IN price per can: the base plus the
                // tint. tint_fee is carried alongside only so the breakdown
                // can be shown — adding the two again would double-charge.
                // A tint recipe pays the mixing fee as of NOW, like the price.
                $tintFee = $cartItem->mix_recipe !== null ? TintRecipe::fee() : 0.0;
                $unitPrice = $variant->price + $tintFee;
                $subtotal = $unitPrice * $quantity;
                $totalAmount += $subtotal;

                $orderItems[] = [
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'custom_hex' => $cartItem->custom_hex,
                    'custom_color_name' => $cartItem->custom_color_name,
                    'tint_fee' => $tintFee,
                    // The recipe travels with the order: it is the counter's
                    // instruction for what goes in the can.
                    'mix_recipe' => $cartItem->mix_recipe,
                ];
            }

            $order = Order::create([
                'user_id' => $request->user()->id,
                'order_date' => now(),
                'order_type' => $validated['order_type'],
                'status' => 'pending',
                'total_amount' => $totalAmount,
                'shipping_address'  => $validated['shipping_address'] ?? null,
                'delivery_landmark' => $validated['delivery_landmark'] ?? null,
                'delivery_lat'      => $validated['delivery_lat'] ?? null,
                'delivery_lng'      => $validated['delivery_lng'] ?? null,
                'location_accuracy' => $validated['location_accuracy'] ?? null,
                // Stamped here, not sent by the client. When a pin was taken
                // is the store's record of it, and it lets checkout ask
                // "pinned 3 months ago — still right?" later on.
                'location_pinned_at' => isset($validated['delivery_lat']) ? now() : null,
            ]);

            // Remember what they actually used.
            //
            // `users.address` was only ever written at registration, so
            // checkout pre-filled a value the customer had no way to improve:
            // typing a better address here was forgotten by the next order and
            // they retyped something short. Persisting it makes the prompt
            // worth improving at all — the address gets better over time
            // instead of resetting. Delivery only; a pickup has no address to
            // remember, and blanking one on a pickup order would lose it.
            if ($validated['order_type'] === 'delivery') {
                $user = $request->user();

                $remember = [
                    'address'  => $validated['shipping_address'] ?? $user->address,
                    'landmark' => $validated['delivery_landmark'] ?? $user->landmark,
                ];

                // The pin is only overwritten when a NEW one was sent. An order
                // placed without pinning must not wipe a good pin the customer
                // set last time — they may simply have been somewhere else, or
                // ordering for somebody else, which is exactly when they are
                // right to skip it.
                if (isset($validated['delivery_lat'], $validated['delivery_lng'])) {
                    $remember += [
                        'delivery_lat'       => $validated['delivery_lat'],
                        'delivery_lng'       => $validated['delivery_lng'],
                        'location_accuracy'  => $validated['location_accuracy'] ?? null,
                        'location_pinned_at' => now(),
                    ];
                }

                $user->update($remember);
            }

            foreach ($orderItems as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['variant']->product_id,
                    'product_variant_id' => $item['variant']->id,
                    'size_volume' => $item['variant']->size_volume,
                    // Snapshotted like size_volume and unit_price: the variant
                    // can later be recoloured, renamed, re-priced or archived,
                    // and this order must still show what was actually bought.
                    'color_code' => $item['variant']->color_code ?: null,
                    'color_name' => $item['variant']->color_name ?: null,
                    'hex_code' => $item['variant']->hex_code,
                    'custom_hex' => $item['custom_hex'],
                    'custom_color_name' => $item['custom_color_name'],
                    'tint_fee' => $item['tint_fee'],
                    // Snapshot: names, hexes and ml as bought. A preset edited
                    // or archived later must not rewrite this order.
                    'mix_recipe' => $item['mix_recipe'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['subtotal'],
                ]);

                $item['variant']->decrement('stock', $item['quantity']);

                InventoryLog::create([
                    'product_id' => $item['variant']->product_id,
                    'product_variant_id' => $item['variant']->id,
                    'action_name' => 'order_placed',
                    'quantity_changed' => -$item['quantity'],
                ]);
            }

            Payment::create([
                'order_id' => $order->id,
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'pending',
                'payment_date' => null,
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

            // ...and ring the admin panel's bell, so the store learns about it
            // without sitting on the orders list waiting for a reload.
            //
            // Guarded: everything from here down runs AFTER the commit, but is
            // still inside the try — an exception would reach a catch that
            // returns "Order failed" for an order that is already placed and
            // paid for, and the customer would order again. A bell that did
            // not ring is not worth a duplicate order.
            try {
                AdminOrderAlertService::orderPlaced($order);
            } catch (\Throwable $e) {
                Log::warning('New-order admin alert failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

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
                'order' => $order->load(['orderItems.product.brand', 'orderItems.variant', 'payment']),
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

        $order->load(['orderItems.product.brand', 'orderItems.variant', 'payment', 'driver']);

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
