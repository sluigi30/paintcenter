<?php

namespace App\Services;

/**
 * A recipe the counter could pour, and how close it actually lands.
 *
 * THE THREE NUMBERS ARE NOT INTERCHANGEABLE, and the naming is the point:
 *
 *   deltaE              the distance of the recipe being RETURNED
 *   searchedMinDeltaE   the best distance found AMONG THE CANDIDATES SEARCHED
 *   tieBreakCost()      what the cheaper recipe cost in accuracy
 *
 * Deliberately not called `minDeltaE`. That name would claim the minimum
 * achievable, and this is only the minimum over what the prune kept — the two
 * are the same number only to the extent the regret tests say so. A reader who
 * computed "how far from optimal are we" from a field called `min_` would
 * believe they had measured distance-from-optimal when they had measured
 * distance-from-the-best-thing-we-looked-at.
 *
 * Only `deltaE` is ever exposed to a client, as `delta_e`. See REACHABILITY.md.
 */
final class SolvedMix
{
    /**
     * @param  array<int,array{ingredient:MixIngredient,count:int}>  $tints
     */
    public function __construct(
        public readonly string $hex,
        public readonly float $deltaE,
        public readonly float $searchedMinDeltaE,
        public readonly MixIngredient $base,
        public readonly array $tints,
        public readonly int $evaluated,
    ) {}

    /** What the fewest-cans preference gave away in accuracy. Must never
     *  exceed config('paint.reachable.tie_tolerance') — pinned by test. */
    public function tieBreakCost(): float
    {
        return $this->deltaE - $this->searchedMinDeltaE;
    }

    /**
     * Whether anything is actually poured.
     *
     * A solve has TWO possible shapes and they are bought down different
     * paths, which is not a detail the client can be left to infer:
     *
     *   mix      base + pints  ->  POST /api/cart/mix
     *   stocked  the can as-is ->  POST /api/cart/add
     *
     * When the shop already stocks the colour there is nothing to mix, and
     * `MixController` rightly refuses a recipe with no tints — "a mix with
     * nothing added to it" is not a mix. Selling it as one would charge for
     * pints nobody needs and hand the counter a mixing sheet for a can they
     * should simply take off the shelf.
     *
     * Discovered by ReachableColorTest asserting every proposed recipe is
     * accepted by the cart, which is exactly what that test is for.
     */
    public function isMix(): bool
    {
        return $this->tints !== [];
    }

    /** Cans the customer buys: the base, plus every pint. */
    public function totalCans(): int
    {
        return 1 + array_sum(array_column($this->tints, 'count'));
    }

    /**
     * What they go home with — BIGGER than the base can they picked. A 4L base
     * with three pints is about 5.4L and needs a container to match, which is
     * why this is shown rather than assumed.
     */
    public function totalLiters(): float
    {
        $liters = $this->base->liters;

        foreach ($this->tints as $tint) {
            $liters += $tint['ingredient']->liters * $tint['count'];
        }

        return round($liters, 3);
    }

    public function totalPrice(): float
    {
        $price = $this->base->price;

        foreach ($this->tints as $tint) {
            $price += $tint['ingredient']->price * $tint['count'];
        }

        return round($price, 2);
    }

    /**
     * How the gap is worded to the customer.
     *
     * A band describes the PREDICTION — Kubelka-Munk over admin-entered screen
     * previews — never the can that comes off the shaker. Until tint_strength
     * is calibrated the model over-predicts dark additions, so no band may be
     * read as a guarantee.
     */
    public function band(): string
    {
        $bands = config('paint.reachable.bands');

        return match (true) {
            $this->deltaE <= $bands['match'] => 'match',
            $this->deltaE <= $bands['close'] => 'close',
            $this->deltaE <= $bands['near'] => 'near',
            default => 'nearest',
        };
    }

    /**
     * Shaped so it can be posted straight back to POST /api/cart/mix — same
     * {base, tints, count} vocabulary, so nothing is translated between the
     * screen that suggests and the endpoint that buys. The server still
     * recomputes and re-locks there; this is a proposal, never an authority.
     */
    public function toRecipePayload(): array
    {
        return [
            'base' => [
                'product_variant_id' => $this->base->variantId,
                'count' => 1,
            ],
            'tints' => array_map(fn (array $tint) => [
                'product_variant_id' => $tint['ingredient']->variantId,
                'count' => $tint['count'],
            ], $this->tints),
        ];
    }

    public function toArray(): array
    {
        return [
            'hex' => $this->hex,
            'delta_e' => round($this->deltaE, 2),
            'band' => $this->band(),

            // Which endpoint buys this — see isMix(). The client must branch
            // on it rather than posting every answer to /cart/mix, which
            // refuses a recipe with no tints.
            'kind' => $this->isMix() ? 'mix' : 'stocked',

            'recipe' => $this->toRecipePayload(),
            'total_liters' => $this->totalLiters(),
            'total_price' => $this->totalPrice(),
        ];
    }
}
