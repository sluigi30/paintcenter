<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\ColorService;
use App\Services\MixCandidates;
use App\Services\MixSolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ColorController extends Controller
{
    /**
     * Turn a colour the customer picked into the facts needed to sell it.
     *
     * The client picks a SIZE, but a variant is (size x base) — so something
     * has to name the base. Doing it here keeps paint chemistry out of the
     * app: the client filters the product's variants by the returned
     * base_code and shows sizes, never the base itself.
     *
     * Public on purpose. Choosing a colour and seeing whether it can be mixed
     * comes before any intent to buy, and gating it behind a login would put
     * a wall in front of the app's best feature.
     */
    public function resolve(Request $request)
    {
        $request->validate([
            'hex' => 'required|string|max:9',
        ]);

        $color = ColorService::describe($request->input('hex'));

        if ($color === null) {
            return response()->json([
                'message' => 'That is not a colour we can read.',
            ], 422);
        }

        return response()->json($color);
    }

    /**
     * Can the shop actually make these colours, and with what recipe?
     *
     * THE one authority behind "we can mix this". The camera suggestions, the
     * AR wall preview and the empty-search recovery all produce a target hex
     * and all come here, so the app cannot hold two opinions about what is
     * makeable. (It used to: /colors/resolve answers a question about a
     * tinting machine this shop does not own, and the bench separately found
     * the nearest stocked can — two rules, one screen. See REACHABILITY.md.)
     *
     * BATCHED because the suggestions screen resolves four at once and four
     * round trips is three too many. Capped at 8: each target is a search over
     * stock, not a lookup.
     *
     * The returned recipe is shaped for POST /api/cart/mix — same
     * {base, tints, count} vocabulary — so nothing is translated between the
     * screen that suggests and the endpoint that buys. That endpoint still
     * re-locks the rows and recomputes the colour; this is a proposal, never
     * an authority.
     *
     * Public, like resolve(), and for the same reason: choosing a colour comes
     * before any intent to buy.
     */
    public function reachable(Request $request)
    {
        $validated = $request->validate([
            'hex' => 'required|array|min:1|max:8',
            'hex.*' => 'required|string|max:9',
        ]);

        $targets = [];

        foreach ($validated['hex'] as $raw) {
            $hex = ColorService::normalizeHex($raw);

            if ($hex === null) {
                return response()->json([
                    'message' => "We cannot read the colour \"{$raw}\".",
                ], 422);
            }

            $targets[] = $hex;
        }

        $signature = $this->stockSignature();
        $solver = new MixSolver;

        // Built at most once per request, and only if something actually
        // misses the cache — a screen re-opened on an unchanged shelf should
        // cost nothing at all.
        $candidates = null;

        $results = [];

        foreach ($targets as $hex) {
            $results[] = Cache::remember(
                "reachable:{$signature}:{$hex}",
                now()->addMinutes(10),
                function () use (&$candidates, $solver, $hex) {
                    $candidates ??= MixCandidates::fromStock();

                    return [
                        'target' => $hex,
                        'best' => $solver->solve($hex, $candidates)?->toArray(),
                    ];
                }
            );
        }

        return response()->json([
            // Reachability describes the shelf RIGHT NOW. A recipe is only as
            // live as the stock behind it, so the answer is stamped rather
            // than read as a standing fact — the same rule the reports apply
            // to every point-in-time figure.
            'as_of' => now()->toIso8601String(),
            'results' => $results,
            'ingredients' => $this->ingredientsIn($results),
        ]);
    }

    /**
     * The cans named by these recipes, in the shape the mixing bench builds a
     * line from.
     *
     * Sent so the app can open the bench on a proposed recipe WITHOUT a second
     * round trip. The obvious alternative — have the client look the ids up
     * through /products?mixable=base — is paginated, so on a real catalogue the
     * variant simply might not be in the page it fetched, and the bench would
     * fail to open for reasons no one could see.
     *
     * Deliberately rebuilt on every request rather than cached alongside the
     * solve: price and stock are shown to the customer here, and a ten-minute
     * old stock figure beside a live recipe is the kind of small lie that ends
     * at the counter. The solve is what is expensive; this is one query over a
     * handful of ids.
     *
     * @param  array<int,array{best:?array}>  $results
     */
    private function ingredientsIn(array $results): array
    {
        $ids = [];

        foreach ($results as $result) {
            if (($recipe = $result['best']['recipe'] ?? null) === null) {
                continue;
            }

            $ids[] = $recipe['base']['product_variant_id'];

            foreach ($recipe['tints'] as $tint) {
                $ids[] = $tint['product_variant_id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        return ProductVariant::query()
            ->with('product.categories:id')
            ->whereIn('id', array_unique($ids))
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant) => [$variant->id => [
                'product_variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'name' => $variant->product?->name ?? '',
                'color_name' => $variant->color_name ?: '',
                'hex_code' => $variant->hex_code,
                'size_volume' => $variant->size_volume,
                'price' => (float) $variant->price,
                'stock' => (int) $variant->stock,
                'tint_strength' => (float) ($variant->tint_strength ?? 1),
                'category_ids' => $variant->product?->categories?->pluck('id')->all() ?? [],
            ]])
            ->all();
    }

    /**
     * A short fingerprint of the shelf, so a cached answer dies the moment the
     * stock it was computed from changes.
     *
     * Three parts, because one is not enough: the COUNT moves when a variant
     * is archived or added, the SUM when anything is sold or restocked, and
     * MAX(updated_at) when a price, a hex or a tint_strength is edited without
     * either of the first two changing. A cache key missing that last one
     * would keep serving recipes built from a colour the admin has since
     * corrected.
     */
    private function stockSignature(): string
    {
        $shelf = ProductVariant::query()
            ->active()
            ->where('stock', '>', 0)
            ->selectRaw('COUNT(*) as variants, COALESCE(SUM(stock), 0) as cans, MAX(updated_at) as touched')
            ->first();

        return substr(sha1(implode('|', [
            (int) ($shelf->variants ?? 0),
            (int) ($shelf->cans ?? 0),
            (string) ($shelf->touched ?? ''),
        ])), 0, 12);
    }
}
