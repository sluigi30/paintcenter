<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\TintColor;
use Illuminate\Support\Collection;

/**
 * The rules of a tint-recipe mix: one base can plus colorant presets by the ml.
 *
 * The ONE place those rules live, shared by POST /cart/mix (which checks the
 * input) and checkout (which re-checks what may have gone away since). The two
 * checks are deliberately NOT the same — see MIXING.md, "Rules the server
 * enforces":
 *
 *   /cart/mix  base valid · presets available · whole steps · under the cap
 *   checkout   base valid · presets available
 *
 * Step and cap are input constraints. An admin later changing a preset's step
 * or a base's cap must not strand carts at checkout for a reason the customer
 * can neither see nor fix by tapping anything.
 */
class TintRecipe
{
    /**
     * Why this variant cannot be mixed into, or null if it can. Stock is the
     * caller's to check against the quantity it wants.
     */
    public static function baseError(?ProductVariant $base): ?string
    {
        if ($base === null || $base->is_archived || ! $base->product || $base->product->is_archived) {
            return 'That base is no longer available.';
        }

        if (! $base->product->is_mixing_base) {
            return 'That paint is not a mixing base.';
        }

        if (ColorService::normalizeHex($base->hex_code) === null) {
            return 'That base has no colour on file, so a mix from it cannot be shown.';
        }

        if ($base->maxTintMl() === null) {
            return "We don't know how much paint is in a {$base->size_volume}, so it can't be mixed.";
        }

        return null;
    }

    /**
     * Validate a customer's recipe and turn it into the snapshot a cart line
     * stores.
     *
     * @param  array<int,array{tint_color_id:int|string,ml:float|int|string}>  $input
     * @return array{error:string}|array{recipe:array<int,array>,hex:string,total_ml:float}
     */
    public static function build(ProductVariant $base, array $input): array
    {
        // Duplicates are summed, not refused: a double-tap or a client retry
        // must not fail a valid recipe, and the counter wants one line per
        // colorant. Whole steps summed are still whole steps.
        $amounts = [];

        foreach ($input as $row) {
            $id = (int) $row['tint_color_id'];
            $amounts[$id] = ($amounts[$id] ?? 0) + (float) $row['ml'];
        }

        $maxTints = (int) config('paint.mix.max_tints');

        if (count($amounts) > $maxTints) {
            return ['error' => "A mix can use at most {$maxTints} different colours."];
        }

        $tints = TintColor::whereKey(array_keys($amounts))->get()->keyBy('id');

        if ($error = self::unavailable($tints, array_keys($amounts), 'Please choose another colour.')) {
            return ['error' => $error];
        }

        $recipe = [];

        // In the admin's order, so the counter's card and the customer's
        // summary list the colours the same way the bench did.
        foreach ($tints->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $tint) {
            $ml = round($amounts[$tint->id], 1);

            if (! $tint->acceptsAmount($ml)) {
                $step = self::formatMl($tint->step_ml);

                return ['error' => "{$tint->name} is added in steps of {$step} ml."];
            }

            $recipe[] = [
                'tint_color_id' => $tint->id,
                'name' => $tint->name,
                'hex' => $tint->hex_code,
                'ml' => $ml,
            ];
        }

        $total = round(array_sum(array_column($recipe, 'ml')), 1);
        $cap = $base->maxTintMl();

        if ($total > $cap) {
            return ['error' => 'That is more colour than a '.$base->size_volume
                .' can holds — at most '.self::formatMl($cap).' ml in total.'];
        }

        $hex = self::predict($base, $recipe, $tints);

        if ($hex === null) {
            return ['error' => 'That mix cannot be previewed. Please choose different colours.'];
        }

        return ['recipe' => $recipe, 'hex' => $hex, 'total_ml' => $total];
    }

    /**
     * The predicted colour of ONE can. Quantity does not enter into it: two
     * cans of a recipe are the same colour as one.
     *
     * The base mixes at strength 1.00; each colorant at its calibrated
     * tint_strength × the base's tint_response, read LIVE — this is only ever
     * called while building a recipe, never to re-colour a placed order. The
     * response is what makes a deep base show the same 20 ml far deeper.
     *
     * @param  Collection<int,TintColor>  $tints  keyed by id
     */
    public static function predict(ProductVariant $base, array $recipe, Collection $tints): ?string
    {
        $response = $base->tintResponse();

        $components = [[
            'hex' => $base->hex_code,
            'liters' => $base->liters,
            'strength' => 1.0,
        ]];

        foreach ($recipe as $row) {
            $components[] = [
                'hex' => $row['hex'],
                'liters' => $row['ml'] / 1000,
                'strength' => (float) ($tints->get($row['tint_color_id'])?->tint_strength ?? 1.0) * $response,
            ];
        }

        return ColorService::mix($components);
    }

    /**
     * What stops a carted recipe line from being ordered now, or null. The
     * checkout half of the rules: the base must still be a mixing base and
     * every preset still offered. Nothing is ever substituted — the customer
     * edits their mix.
     */
    public static function checkoutError(CartItem $item): ?string
    {
        if ($error = self::baseError($item->variant)) {
            return $error;
        }

        $ids = array_map(fn ($row) => (int) $row['tint_color_id'], $item->mix_recipe ?? []);

        if ($ids === []) {
            return 'This mix has no colours in it. Please edit your mix before checking out.';
        }

        return self::unavailable(
            TintColor::whereKey($ids)->get()->keyBy('id'),
            $ids,
            'Please edit your mix before checking out.',
        );
    }

    /** The first named preset that is missing or archived, worded for the customer. */
    private static function unavailable(Collection $tints, array $ids, string $then): ?string
    {
        foreach ($ids as $id) {
            $tint = $tints->get($id);

            if ($tint === null || $tint->is_archived) {
                $name = $tint?->name ?? 'One of the colours in your mix';

                return "{$name} is no longer available for mixing. {$then}";
            }
        }

        return null;
    }

    /**
     * The mixing fee per can, as of NOW. Carts and checkout both charge the
     * current fee, as ordinary lines charge the current price — the copy on a
     * cart line is for display only.
     */
    public static function fee(): float
    {
        return round((float) config('paint.mix.fee'), 2);
    }

    /** 0.5 -> "0.5", 2.0 -> "2", 240.0 -> "240". */
    public static function formatMl(float $ml): string
    {
        return rtrim(rtrim(number_format($ml, 1, '.', ''), '0'), '.');
    }
}
