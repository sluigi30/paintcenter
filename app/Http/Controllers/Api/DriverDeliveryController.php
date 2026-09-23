<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\DeliveryProofService;
use App\Services\DeliveryService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The driver app's half of the delivery flow.
 *
 * Every endpoint here is a thin wrapper over DeliveryService — validate, call,
 * serialise. That is the return on extracting it in Phase 1: the /driver
 * Filament panel and this controller press the same buttons, so the rules
 * (who owns the order, COD cash, the attempt cap, who gets told what) cannot
 * come to mean two different things depending on which client is in hand.
 *
 * Responses are SHAPED, not model dumps. A driver needs the customer's name,
 * number, address and what is in the box; they have no business receiving the
 * customer's email, and the store has no business handing over which admin
 * assigned the run. Dumping $order and patching the leaks afterwards is how
 * the customer endpoints ended up needing an $hidden list.
 */
class DriverDeliveryController extends Controller
{
    /** The tabs the app shows, and the statuses behind each. */
    private const TABS = [
        'to_pick_up' => ['processing'],
        'out'        => ['shipped'],
        'history'    => ['completed', 'cancelled'],
    ];

    /**
     * This driver's own deliveries.
     *
     * Scoped on driver_id here and nowhere else, so a hand-written id for
     * somebody else's order falls out of the query as "not found" rather than
     * being refused — a 403 on a row that exists is a difference anyone can
     * measure by counting. Pickups never appear: they are handed over at the
     * counter and are never assigned.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'tab' => ['sometimes', Rule::in(array_keys(self::TABS))],
        ]);

        $tab = $validated['tab'] ?? 'to_pick_up';

        $orders = $this->scope($request)
            ->whereIn('status', self::TABS[$tab])
            // Oldest first for work, newest first for history: a queue is read
            // from the front, a log from the top.
            ->orderBy('created_at', $tab === 'history' ? 'desc' : 'asc')
            ->get();

        return response()->json([
            'tab'        => $tab,
            'deliveries' => $orders->map(fn (Order $order) => $this->summary($order))->all(),
        ]);
    }

    public function show(Request $request, int $order)
    {
        return response()->json($this->detail($this->findOrFail($request, $order)));
    }

    /** The two integers behind the app's tab badges. */
    public function summaryCounts(Request $request)
    {
        return response()->json([
            'to_pick_up' => (clone $this->scope($request))->where('status', 'processing')->count(),
            'out'        => (clone $this->scope($request))->where('status', 'shipped')->count(),
            'delivered_today' => (clone $this->scope($request))
                ->where('status', 'completed')
                ->whereDate('delivered_at', today())
                ->count(),
        ]);
    }

    /** processing → shipped. */
    public function pickUp(Request $request, int $order)
    {
        $record = $this->findOrFail($request, $order);

        return $this->run(fn () => DeliveryService::pickUp($record, $request->user()), $record);
    }

    /**
     * shipped → completed.
     *
     * `cash_collected` is required on a COD order and enforced again inside
     * DeliveryService — a client that forgets the field gets a 422, not a
     * silent handover with the money unaccounted for.
     */
    public function deliver(Request $request, int $order)
    {
        $record = $this->findOrFail($request, $order);

        // MULTIPART, because of the photo. `cash_collected` therefore arrives
        // as a string ("1"/"0") rather than a JSON boolean, which is why the
        // rule is `boolean` (Laravel accepts both forms) and the cast below is
        // filter_var rather than a plain (bool) — (bool) "0" is true.
        $validated = $request->validate(
            [
                'cash_collected' => [
                    Rule::requiredIf(fn () => $record->isCashOnDelivery()),
                    'boolean',
                ],
            ] + DeliveryProofService::rules(),
            [
                'cash_collected.required' => 'Confirm the cash was collected before marking this delivered.',
            ] + DeliveryProofService::validationMessages(),
        );

        return $this->run(
            fn () => DeliveryService::deliver(
                $record,
                $request->user(),
                filter_var($validated['cash_collected'] ?? false, FILTER_VALIDATE_BOOLEAN),
                $request->file('proof'),
            ),
            $record,
        );
    }

    /**
     * Tried, came back with it. No status change — a failed attempt is a fact
     * about the trip, not a new place in the journey.
     */
    public function fail(Request $request, int $order)
    {
        $record = $this->findOrFail($request, $order);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        return $this->run(
            fn () => DeliveryService::recordFailedAttempt($record, $request->user(), $validated['reason']),
            $record,
        );
    }

    /** The preset reasons the app offers, so the two lists cannot drift. */
    public function failureReasons()
    {
        return response()->json([
            'reasons'      => DeliveryService::FAILURE_REASONS,
            'max_attempts' => DeliveryService::MAX_ATTEMPTS,
        ]);
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    private function scope(Request $request)
    {
        return Order::query()
            ->where('driver_id', $request->user()->id)
            ->where('order_type', 'delivery')
            ->with(['user', 'payment', 'orderItems.product']);
    }

    private function findOrFail(Request $request, int $order): Order
    {
        return $this->scope($request)->findOrFail($order);
    }

    /**
     * Run a DeliveryService call and turn its refusals into a 422.
     *
     * A DomainException is a rule the server enforced that the app thought was
     * satisfied — usually because somebody at the store moved the order while
     * the driver was looking at it. A StatusChange that did not succeed is the
     * same situation seen from the other side. Both are the client's cue to
     * refresh, not a 500.
     */
    private function run(callable $call, Order $record)
    {
        try {
            $result = $call();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result instanceof \App\Services\StatusChange && ! $result->succeeded()) {
            return response()->json([
                'message' => 'Someone at the store changed this order. Pull down to refresh.',
            ], 409);
        }

        return response()->json(['delivery' => $this->detail($record->fresh())]);
    }

    /** The list row: enough to decide what to do next, and nothing more. */
    private function summary(Order $order): array
    {
        return [
            'id'           => $order->id,
            // Orders are named by when they were placed, never by id — so the
            // driver and the customer are talking about the same thing when
            // one phones the other.
            'placed_at'    => $order->created_at,
            'status'       => $order->status,
            'customer'     => $order->user?->name,
            'address'      => $order->shipping_address,
            // Directions, not an address. Shown to the driver, never fed to
            // the map query — "green gate" only makes a geocode worse.
            'landmark'     => $order->delivery_landmark,
            // The pin, when the customer set one. The app targets Navigate at
            // these instead of the address string; accuracy travels with them
            // so a ±480 m fix is never drawn as a doorstep.
            'lat'          => $order->hasLocationPin() ? (float) $order->delivery_lat : null,
            'lng'          => $order->hasLocationPin() ? (float) $order->delivery_lng : null,
            'accuracy_m'   => $order->location_accuracy,
            'total'        => $order->total_amount,
            'is_cod'       => $order->isCashOnDelivery(),
            'attempts'     => $order->failed_attempts,
            'max_attempts' => DeliveryService::MAX_ATTEMPTS,
        ];
    }

    /** The detail screen: the list row plus what actually goes in the box. */
    private function detail(Order $order): array
    {
        $order->loadMissing(['user', 'payment', 'orderItems.product']);

        return $this->summary($order) + [
            'customer_phone'   => $order->user?->phone,
            'payment_method'   => $order->payment?->payment_method,
            'picked_up_at'     => $order->picked_up_at,
            'delivered_at'     => $order->delivered_at,
            'delivery_note'    => $order->delivery_note,
            // The driver's own record of a job they did. Route, not a storage
            // path — the gate is in DeliveryProofController.
            'proof_url'        => $order->hasProof()
                ? route('api.orders.proof', ['order' => $order->getKey()])
                : null,
            'proof_captured_at' => $order->proof_captured_at,
            'can_pick_up'      => $order->status === 'processing',
            'can_deliver'      => $order->status === 'shipped',
            'can_report_fail'  => $order->isOutForDelivery() && ! DeliveryService::attemptsExhausted($order),
            'items'            => $order->orderItems->map(fn ($item) => [
                'quantity' => $item->quantity,
                'name'     => $item->product?->name,
                'color'    => $item->display_color,
                'size'     => $item->size_volume,
                'hex'      => $item->hex_code ?: $item->custom_hex,
            ])->all(),
        ];
    }
}
