<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TintColor;
use App\Services\ColorService;
use App\Services\TintRecipe;
use App\Services\TintSolver;
use Illuminate\Http\Request;

/**
 * Customer-composed colours.
 *
 * A TINT RECIPE (current): one base can — a product flagged is_mixing_base —
 * with colorant presets added by the ml, bought as one line at base price plus
 * a flat mixing fee. bases() / tints() feed the bench, solve() proposes a
 * recipe for a target colour, storeRecipe() carts one. The rules live in
 * TintRecipe, the search in TintSolver.
 *
 * The older PINT MIX (stocked pint cans poured into a base can) was retired
 * 2026-09-24 — see MIXING_PINTS.md. Placed pint-mix orders still display.
 *
 * Not an extension of CartController::add(): that sells a can as it comes and
 * refuses any colour sent with it. Here the server derives the colour and the
 * price from the recipe; the client is trusted with neither.
 *
 * See MIXING.md.
 */
class MixController extends Controller
{
    /**
     * A build of the app from before tint recipes posts {base, tints} of pint
     * cans. That flow is retired; say so plainly rather than answering with a
     * validation error about fields the customer never saw.
     */
    public function store(Request $request)
    {
        if ($request->has('base') && ! $request->has('base_variant_id')) {
            return response()->json([
                'message' => 'Mixing has changed. Please update the app to mix your colour.',
            ], 422);
        }

        return $this->storeRecipe($request);
    }

    /**
     * What a mix can start from: mixing-base products and their cans.
     *
     * Only cans the server would accept are listed — a colour on file and a
     * known volume — so the bench never offers a base /cart/mix then refuses.
     * Out-of-stock cans ARE listed, marked, so a size that has sold out reads
     * as sold out rather than as never existing. The fee rides along because
     * the bench prices every can with it.
     */
    public function bases()
    {
        $products = Product::mixingBases()
            ->with(['brand', 'activeVariants'])
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) {
                $variants = $product->activeVariants
                    ->filter(fn (ProductVariant $v) => TintRecipe::baseError($v->setRelation('product', $product)) === null)
                    ->map(fn (ProductVariant $v) => [
                        'id' => $v->id,
                        'base_name' => $v->color_name ?: $product->name,
                        'base_type' => $v->base_type,
                        'hex_code' => $v->hex_code,
                        // The app's preview multiplies colorant by this, as
                        // the server does; without it a deep base previews pale.
                        'tint_response' => $v->tintResponse(),
                        'size_volume' => $v->size_volume,
                        'volume_liters' => $v->liters,
                        'max_tint_ml' => $v->maxTintMl(),
                        'price' => (float) $v->price,
                        'stock' => $v->stock,
                        'stock_status' => $v->stock_status,
                    ])
                    ->values();

                return [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'brand' => $product->brand ? ['id' => $product->brand->id, 'brand_name' => $product->brand->brand_name] : null,
                    'image' => $product->image,
                    'variants' => $variants,
                ];
            })
            ->filter(fn ($p) => $p['variants']->isNotEmpty())
            ->values();

        return response()->json([
            'fee' => TintRecipe::fee(),
            'bases' => $products,
        ]);
    }

    /**
     * The colorant presets, in the admin's order.
     *
     * tint_strength is served because the app previews the mix locally and has
     * to use the same calibration the server predicts with — otherwise the
     * swatch under the customer's finger and the colour in their cart differ.
     */
    public function tints()
    {
        return response()->json([
            'max_tints' => (int) config('paint.mix.max_tints'),
            'tints' => TintColor::available()->get()->map(fn (TintColor $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'hex_code' => $t->hex_code,
                'step_ml' => $t->step_ml,
                'tint_strength' => $t->tint_strength,
            ]),
        ]);
    }

    /**
     * Propose a recipe for a colour, for every base of one size.
     *
     * The photo eyedropper, the AR preview and the colour suggestions all end
     * here with a target hex and the size the customer wants. The customer
     * knows the size; the colour decides the base — so each base of that size
     * is solved and ranked, and the first is the recommendation. The others
     * come back too, so "Change base" can say honestly what each one gives up.
     *
     * Ranked by BAND, then by the least colorant, then by ΔE. Within a band the
     * differences are not ones a person sees, but half a litre of colorant is:
     * a deep base "reaching" a pastel by pouring in 445 ml of white colorant
     * must not outrank a white base that needs 33 ml.
     *
     * Every recipe returned is one POST /cart/mix accepts: whole steps, within
     * the can's cap, available presets. The ΔE is that of the snapped recipe —
     * the one that would be bought — never of an unrounded ideal.
     */
    public function solve(Request $request)
    {
        $validated = $request->validate([
            'hex' => ['required', 'string', function ($attr, $value, $fail) {
                if (ColorService::normalizeHex($value) === null) {
                    $fail('That is not a colour.');
                }
            }],
            'liters' => 'required|numeric|gt:0|max:100',
        ]);

        $target = ColorService::normalizeHex($validated['hex']);
        $liters = (float) $validated['liters'];

        $tints = TintColor::available()->get();

        $bases = ProductVariant::with('product')
            ->active()
            ->whereHas('product', fn ($q) => $q->where('is_mixing_base', true))
            ->where('stock', '>', 0)
            ->get()
            ->filter(fn (ProductVariant $v) => TintRecipe::baseError($v) === null
                && abs((float) $v->liters - $liters) < 0.001);

        if ($tints->isEmpty() || $bases->isEmpty()) {
            return response()->json([
                'message' => 'There is nothing in stock to mix that size from right now.',
            ], 422);
        }

        $solver = new TintSolver(
            $tints->map(fn (TintColor $t) => [
                'id' => $t->id, 'name' => $t->name, 'hex' => $t->hex_code,
                'step' => $t->step_ml, 'strength' => $t->tint_strength,
            ])->all(),
            maxTints: min(3, (int) config('paint.mix.max_tints')),
        );

        $rank = ['match' => 0, 'close' => 1, 'near' => 2, 'nearest' => 3];

        $results = $bases->map(function (ProductVariant $base) use ($solver, $target) {
            $best = $solver->solve($target, [
                'hex' => $base->hex_code,
                'liters' => $base->liters,
                'cap' => $base->maxTintMl(),
                'response' => $base->tintResponse(),
            ]);

            return [
                'base_variant_id' => $base->id,
                'product_id' => $base->product_id,
                'base_name' => $base->color_name ?: $base->product->name,
                'size_volume' => $base->size_volume,
                'base_hex' => $base->hex_code,
                'price' => (float) $base->price,
                'hex' => $best['hex'],
                'delta_e' => round($best['delta_e'], 2),
                'band' => self::band($best['delta_e']),
                'recipe' => $best['recipe'],
                'total_ml' => round(array_sum(array_column($best['recipe'], 'ml')), 1),
            ];
        })
            ->sort(fn ($a, $b) => [$rank[$a['band']], $a['total_ml'], $a['delta_e']]
                <=> [$rank[$b['band']], $b['total_ml'], $b['delta_e']])
            ->values();

        return response()->json([
            'target' => $target,
            'liters' => $liters,
            'fee' => TintRecipe::fee(),
            'results' => $results,
        ]);
    }

    /** SolvedMix::band(), the words the customer reads about a match. */
    private static function band(float $deltaE): string
    {
        $bands = config('paint.mix.bands');

        return match (true) {
            $deltaE <= $bands['match'] => 'match',
            $deltaE <= $bands['close'] => 'close',
            $deltaE <= $bands['near'] => 'near',
            default => 'nearest',
        };
    }

    /**
     * One base can with colorant presets added by the ml — ONE cart line.
     *
     * The client sends ids and amounts only. The colour, the validity of every
     * amount and the price are all settled here, from live rows: the client is
     * not trusted with the value that decides what goes in the can, and its
     * copy of the presets may be stale.
     */
    private function storeRecipe(Request $request)
    {
        $validated = $request->validate([
            'base_variant_id' => 'required|integer|exists:product_variants,id',
            'quantity' => 'sometimes|integer|min:1|max:99',
            'tints' => 'required|array|min:1',
            'tints.*.tint_color_id' => 'required|integer',
            'tints.*.ml' => 'required|numeric|gt:0|max:9999',
            'mix_color_name' => 'nullable|string|max:60',
        ], [
            // A mix with nothing in it is a can of plain base.
            'tints.required' => 'Add at least one colour to the base before adding the mix to your cart.',
            'tints.min' => 'Add at least one colour to the base before adding the mix to your cart.',
        ]);

        $base = ProductVariant::with('product')->find($validated['base_variant_id']);
        $quantity = (int) ($validated['quantity'] ?? 1);

        if ($error = TintRecipe::baseError($base)) {
            return response()->json(['message' => $error], 422);
        }

        // Not reserved — checkout takes stock under lock, as /cart/add does.
        if ($base->stock < $quantity) {
            return response()->json([
                'message' => $base->stock === 0
                    ? "{$base->display_name} is out of stock."
                    : "Only {$base->stock} of {$base->display_name} left.",
            ], 422);
        }

        $built = TintRecipe::build($base, $validated['tints']);

        if (isset($built['error'])) {
            return response()->json(['message' => $built['error']], 422);
        }

        // Never merged with another line: two recipes can predict the same
        // hex and still be different amounts of different colorants.
        CartItem::create([
            'user_id' => $request->user()->id,
            'product_id' => $base->product_id,
            'product_variant_id' => $base->id,
            'quantity' => $quantity,
            'custom_hex' => $built['hex'],
            'custom_color_name' => $validated['mix_color_name'] ?? null,
            // Display copy only; the cart and checkout charge the CURRENT fee.
            'tint_fee' => TintRecipe::fee(),
            'mix_recipe' => $built['recipe'],
        ]);

        return app(CartController::class)->summary($request);
    }
}
