<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Services\ColorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer-composed colours.
 *
 * The shop has no tinting machine, so a custom colour is made by pouring pint
 * cans of real stocked paint into a base can. The customer composes the recipe
 * and buys every ingredient in it; the counter does the pouring.
 *
 * This does NOT extend CartController::add(). That action refuses a custom_hex
 * on any product not flagged is_custom_color — which protects ready-mixed paint
 * from stray colours and must keep doing so — and where the flag IS set it
 * overrides the client's variant choice by deriving a base from the target hex.
 * A recipe has no single authoritative target for that guard to check against.
 *
 * The whole recipe arrives in ONE request and is written in ONE transaction.
 * The sequential per-line posting used by the estimator and Buy Again is right
 * for independent cans and wrong here: half a recipe in the cart is not a
 * partial order, it is a different colour.
 *
 * See MIXING.md.
 */
class MixController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'base' => 'required|array',
            'base.product_variant_id' => 'required|integer|exists:product_variants,id',
            'base.count' => 'sometimes|integer|min:1|max:99',

            'tints' => 'required|array|min:1',
            'tints.*.product_variant_id' => 'required|integer|exists:product_variants,id',
            'tints.*.count' => 'required|integer|min:1|max:99',

            'mix_color_name' => 'nullable|string|max:60',
        ], [
            'tints.required' => 'Add at least one colour to the base before adding the mix to your cart.',
            'tints.min' => 'Add at least one colour to the base before adding the mix to your cart.',
        ]);

        $user = $request->user();
        $baseId = (int) $validated['base']['product_variant_id'];
        $baseCount = (int) ($validated['base']['count'] ?? 1);

        // Fold duplicate entries for the same tint into one component. A
        // double-tap or a client retry should not fail a recipe that is
        // perfectly valid, and one line per colourant is what the counter
        // needs to read off the sheet.
        $tintCounts = [];

        foreach ($validated['tints'] as $tint) {
            $id = (int) $tint['product_variant_id'];
            $tintCounts[$id] = ($tintCounts[$id] ?? 0) + (int) $tint['count'];
        }

        $maxTints = (int) config('paint.mix.max_tints');

        if (count($tintCounts) > $maxTints) {
            return response()->json([
                'message' => "A mix can use at most {$maxTints} different colours.",
            ], 422);
        }

        return DB::transaction(function () use ($user, $baseId, $baseCount, $tintCounts, $validated, $request) {
            // Lock every variant the recipe touches, in ascending id order.
            // Two customers mixing overlapping colours at the same moment
            // would otherwise be free to take these rows in opposite orders,
            // which is a deadlock waiting for load.
            $ids = collect([$baseId])->merge(array_keys($tintCounts))->unique()->sort()->values();

            $variants = ProductVariant::with('product')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $base = $variants->get($baseId);

            if ($error = $this->rejectBase($base)) {
                return $error;
            }

            foreach ($tintCounts as $id => $count) {
                if ($error = $this->rejectTint($variants->get($id))) {
                    return $error;
                }
            }

            // Stock is counted in CANS. A variant used as both the base and a
            // tint is wanted twice over, so demand is totalled per variant
            // before it is checked — checking each role alone would wave
            // through a recipe the shelf cannot fill.
            $demand = [$baseId => $baseCount];

            foreach ($tintCounts as $id => $count) {
                $demand[$id] = ($demand[$id] ?? 0) + $count;
            }

            foreach ($demand as $id => $wanted) {
                $variant = $variants->get($id);

                if ($variant->stock < $wanted) {
                    return response()->json([
                        'message' => $variant->stock === 0
                            ? "{$variant->display_name} is out of stock."
                            : "Only {$variant->stock} of {$variant->display_name} left — the mix needs {$wanted}.",
                        'product_variant_id' => $id,
                    ], 422);
                }
            }

            // The colour is computed HERE, from the rows just locked. The
            // client never sends a hex: it is not trusted with the value that
            // decides what goes in the can, and its copy of the price list and
            // the maths may both be stale.
            //
            // The base always mixes at strength 1.00. tint_strength calibrates
            // how hard an ADDITION pulls; applying the base's own factor would
            // shift a recipe that has had nothing added to it yet.
            $components = [[
                'hex' => $base->hex_code,
                'liters' => $base->liters * $baseCount,
                'strength' => 1.0,
            ]];

            foreach ($tintCounts as $id => $count) {
                $variant = $variants->get($id);

                $components[] = [
                    'hex' => $variant->hex_code,
                    'liters' => $variant->liters * $count,
                    'strength' => (float) $variant->tint_strength,
                ];
            }

            $mixHex = ColorService::mix($components);

            if ($mixHex === null) {
                return response()->json([
                    'message' => 'That mix cannot be previewed. Please choose different paints.',
                ], 422);
            }

            // Server-generated, always. A client-supplied group would let a
            // request post lines into an existing mix — with a guessed uuid,
            // somebody else's.
            $mixGroup = (string) Str::uuid();
            $mixName = $validated['mix_color_name'] ?? null;

            $this->line($user->id, $base, $baseCount, 'base', $mixGroup, $mixHex, $mixName);

            foreach ($tintCounts as $id => $count) {
                $this->line($user->id, $variants->get($id), $count, 'tint', $mixGroup, $mixHex, $mixName);
            }

            return app(CartController::class)->summary($request);
        });
    }

    /**
     * A base is any stocked paint the customer wants to start from — any size,
     * not just a pint. The whole design is a 4L base with pints poured in.
     */
    private function rejectBase(?ProductVariant $base)
    {
        if ($base === null || $base->is_archived || $base->product?->is_archived) {
            return response()->json(['message' => 'That base paint is no longer available.'], 422);
        }

        if (ColorService::normalizeHex($base->hex_code) === null) {
            return response()->json([
                'message' => 'That paint has no colour on file, so a mix from it cannot be shown.',
            ], 422);
        }

        if ($base->liters === null) {
            return response()->json([
                'message' => "We don't know how much paint is in a {$base->size_volume}, so it can't be mixed.",
            ], 422);
        }

        return null;
    }

    /**
     * Tints are pints only. Eligibility is settled HERE and not in the picker:
     * the app filters for convenience, but a request can name any id at all.
     */
    private function rejectTint(?ProductVariant $tint)
    {
        if ($tint === null || $tint->is_archived || $tint->product?->is_archived) {
            return response()->json(['message' => 'One of those colours is no longer available.'], 422);
        }

        if (! $tint->is_pint) {
            return response()->json([
                'message' => "Colours are added by the pint. {$tint->display_name} can't be used in a mix.",
            ], 422);
        }

        if (ColorService::normalizeHex($tint->hex_code) === null) {
            return response()->json([
                'message' => "{$tint->display_name} has no colour on file and can't be mixed in.",
            ], 422);
        }

        return null;
    }

    /**
     * One ingredient of the mix.
     *
     * custom_hex carries the PREDICTED result on every line of the group, not
     * the ingredient's own colour. That is deliberate: it makes the whole mix
     * read as custom to code that already exists — the badge, the swatch, the
     * counter's sheet, and above all the cancellation rule, since mixed paint
     * cannot be un-mixed any more than tinted paint can.
     *
     * tint_fee is 0. The fee paid for colourant out of a dispenser; here the
     * customer is buying every ingredient outright, so charging it again would
     * be charging twice for the same paint.
     */
    private function line(
        int $userId,
        ProductVariant $variant,
        int $count,
        string $role,
        string $mixGroup,
        string $mixHex,
        ?string $mixName,
    ): void {
        CartItem::create([
            'user_id' => $userId,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'quantity' => $count,
            'custom_hex' => $mixHex,
            'custom_color_name' => $mixName,
            'tint_fee' => 0,
            'mix_group' => $mixGroup,
            'mix_role' => $role,
            // Litres in ONE can. The line contributes quantity * this.
            'mix_liters' => $variant->liters,
        ]);
    }
}
