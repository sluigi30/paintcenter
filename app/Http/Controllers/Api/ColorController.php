<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\ColorService;
use App\Services\TintSolver;
use Illuminate\Http\Request;

class ColorController extends Controller
{
    /**
     * Is this colour already on the shelf as a finished paint?
     *
     * The one question a target colour asks BEFORE the mixing bench. The AR
     * preview, the camera suggestions and a retired deep link all produce a
     * hex; when the shop already sells that colour ready-mixed, the customer
     * should be handed the can, not charged a mixing fee to have one made.
     * Everything else goes to the bench, where /mix/solve proposes a recipe.
     *
     * "Already sells it" means within the MATCH band (ΔE2000) of an in-stock
     * catalogue variant. A near miss is not the colour asked for, and sending
     * the customer to a can that is visibly different would be the same
     * substitution the bench refuses to make silently.
     *
     * Mixing bases are never answers: they are sold only mixed.
     *
     * Replaces /colors/resolve (a tinting machine the shop never had) and
     * /colors/reachable (pint pours). BATCHED because the suggestions screen
     * asks for four at once; capped at 8. Public, like the catalogue.
     */
    public function stocked(Request $request)
    {
        $validated = $request->validate([
            'hex' => 'required|array|min:1|max:8',
            'hex.*' => 'required|string|max:9',
        ]);

        $targets = [];

        foreach ($validated['hex'] as $raw) {
            $hex = ColorService::normalizeHex($raw);

            if ($hex === null) {
                return response()->json(['message' => "We cannot read the colour \"{$raw}\"."], 422);
            }

            $targets[] = $hex;
        }

        // One query for the whole batch; a catalogue is hundreds of cans, and
        // ΔE over each is microseconds.
        $shelf = ProductVariant::query()
            ->active()
            ->whereHas('product', fn ($q) => $q->where('is_mixing_base', false))
            ->where('stock', '>', 0)
            ->whereNotNull('hex_code')
            ->where('hex_code', '!=', '')
            ->get(['id', 'product_id', 'color_name', 'color_code', 'hex_code', 'size_volume']);

        $limit = (float) config('paint.mix.bands.match');

        $results = array_map(function (string $target) use ($shelf, $limit) {
            $best = null;
            $bestDe = INF;

            foreach ($shelf as $variant) {
                $de = ColorService::distance($target, $variant->hex_code);

                if ($de !== null && $de < $bestDe) {
                    $bestDe = $de;
                    $best = $variant;
                }
            }

            return [
                'target' => $target,
                'match' => $best && $bestDe <= $limit ? [
                    'product_id' => $best->product_id,
                    'product_variant_id' => $best->id,
                    'hex' => ColorService::normalizeHex($best->hex_code),
                    'label' => $best->color_label,
                    'delta_e' => round($bestDe, 2),
                ] : null,
            ];
        }, $targets);

        return response()->json([
            // Stock is point-in-time: stamped, never read as a standing fact.
            'as_of' => now()->toIso8601String(),
            'results' => $results,
        ]);
    }
}
