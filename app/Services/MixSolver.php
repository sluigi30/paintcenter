<?php

namespace App\Services;

/**
 * "Can this shop make this colour?" — the one authority.
 *
 * Given a hex the customer wants, search recipes over what is in stock and
 * return the best one the counter could actually pour. Suggestions, the AR
 * wall preview and the empty-search recovery are three ways of producing a
 * target; all three come here, so the app cannot hold two opinions about what
 * is makeable. (It used to: a latex tinting gamut that models a machine the
 * shop does not own, and a nearest-stocked-can search, answering the same
 * question differently on the same screen.)
 *
 * WHAT "BEST" MEANS HERE, precisely:
 *
 *   the closest recipe THE SEARCH FOUND, then traded down by the tie-break
 *
 * which is not the same as the closest recipe that exists. Both prunes below
 * are heuristics. Their cost is measured against an exhaustive reference
 * solver in tests/Support, reported as regret, and pinned — so the gap is a
 * known number rather than an argument. See REACHABILITY.md.
 *
 * The solver is server-side only and is NOT mirrored in JS. lib/paintMix.js
 * mirrors the PREDICTION so the swatch moves under the customer's finger;
 * solving needs stock, and the client is never trusted with the value that
 * decides what goes in the can.
 */
class MixSolver
{
    /** Tints probed per base when ranking bases. Small on purpose: this runs
     *  over the whole shelf and only has to ORDER bases, not solve them. */
    private const LOOKAHEAD_TINTS = 3;

    /** Recipes are scored against the target in CIELAB; caching the Lab of a
     *  predicted hex matters because many recipes land on the same colour. */
    private array $labCache = [];

    private int $evaluated = 0;

    public function __construct(
        private readonly ?int $baseCap = null,
        private readonly ?int $tintCap = null,
        private readonly ?int $maxPerTint = null,
        private readonly ?int $maxDistinctTints = null,
        private readonly ?float $tieTolerance = null,
    ) {}

    public function solve(string $targetHex, MixCandidates $candidates): ?SolvedMix
    {
        $targetLab = ColorService::hexToLab($targetHex);

        if ($targetLab === null || $candidates->isEmpty()) {
            return null;
        }

        $this->evaluated = 0;

        $tieTolerance = $this->tieTolerance ?? (float) config('paint.reachable.tie_tolerance');

        $searchedMin = INF;
        $shortlist = [];

        foreach ($this->rankedBases($candidates, $targetLab) as $base) {
            $tints = $this->rankedTints($candidates, $base, $targetLab);

            foreach ($this->recipesFor($base, $tints) as $recipe) {
                $delta = $this->scoreOf($base, $recipe, $targetLab);

                // A new best can push earlier entries outside the tie window,
                // so the shortlist is re-filtered rather than only appended to.
                if ($delta < $searchedMin) {
                    $searchedMin = $delta;
                    $shortlist = array_values(array_filter(
                        $shortlist,
                        fn (array $entry) => $entry['delta'] <= $searchedMin + $tieTolerance,
                    ));
                }

                if ($delta <= $searchedMin + $tieTolerance) {
                    $shortlist[] = [
                        'delta' => $delta,
                        'hex' => $this->predict($base, $recipe),
                        'base' => $base,
                        'tints' => $recipe,
                    ];
                }
            }
        }

        if ($shortlist === []) {
            return null;
        }

        $chosen = $this->pickCheapest($shortlist, $searchedMin, $tieTolerance);

        return new SolvedMix(
            hex: $chosen['hex'],
            deltaE: $chosen['delta'],
            searchedMinDeltaE: $searchedMin,
            base: $chosen['base'],
            tints: $chosen['tints'],
            evaluated: $this->evaluated,
        );
    }

    // -------------------------------------------------------
    // Pruning — heuristic, and labelled as such
    // -------------------------------------------------------

    /**
     * Bases most likely to REACH the target first, capped.
     *
     * This ranks on a cheap lookahead — the best a base achieves with a single
     * probe tint — and NOT on its own distance to the target. That is a
     * correction, and the measurement behind it is worth keeping:
     *
     *   Ranking bases by raw ΔE looks obviously right (the reach table says
     *   pints move a large can very little, so the base carries the colour)
     *   and measured as the single largest source of lost recipes. On the
     *   harness catalogues the winning base sat OUTSIDE a top-10 raw-ΔE cut on
     *   6 of 24 targets, and in the worst case ranked 22nd of 26 at 37 ΔE from
     *   the target while still reaching it to within 1.6.
     *
     *   The reason is that distance does not predict reachability. What
     *   matters is whether a base sits somewhere the available tints can pull
     *   ONTO the target — a far base pointing the right way beats a near one
     *   no tint can correct. Nor is this only the 1L-travels-further effect:
     *   the worst case was a 4L can.
     *
     * So the ranking asks the question directly instead of proxying it. The
     * probe is deliberately tiny — the best tints for this base, at one can
     * and at the most it can take — because this runs over every base on the
     * shelf and only has to order them, not solve them.
     *
     * Still a heuristic, and still measured: see MixSolverRegretTest.
     *
     * @return MixIngredient[]
     */
    private function rankedBases(MixCandidates $candidates, array $targetLab): array
    {
        $cap = $this->baseCap ?? (int) config('paint.reachable.base_cap');
        $bases = $candidates->bases;

        // Nothing to choose between — skip the probes entirely.
        if (count($bases) <= $cap) {
            return $bases;
        }

        $scored = array_map(fn (MixIngredient $base) => [
            'base' => $base,
            'reach' => $this->reachEstimate($base, $candidates, $targetLab),
        ], $bases);

        usort($scored, fn ($a, $b) => $a['reach'] <=> $b['reach']
            ?: $a['base']->variantId <=> $b['base']->variantId);

        return array_column(array_slice($scored, 0, $cap), 'base');
    }

    /**
     * Roughly how close this base can get, for ranking purposes only.
     *
     * The bare base, plus each of a few well-aimed tints at one can and at the
     * most the shelf allows. Never used as an answer — only to decide which
     * bases are worth enumerating properly.
     */
    private function reachEstimate(MixIngredient $base, MixCandidates $candidates, array $targetLab): float
    {
        $best = ColorService::deltaE2000($base->lab, $targetLab);

        $maxPerTint = $this->maxPerTint ?? (int) config('paint.reachable.max_per_tint');

        // Capped HERE rather than by slicing the main ranking, so the base
        // order does not shift with tintCap. Without that the two caps are
        // coupled — widening the tint cap would re-rank the BASES, the
        // candidate set would stop being a superset of the narrower run, and
        // the monotonicity relation in MixSolverRegretTest would be asserting
        // something that is not structurally true.
        $probes = $this->rankedTints($candidates, $base, $targetLab, self::LOOKAHEAD_TINTS);

        foreach ($probes as $tint) {
            $most = $this->affordableCans($tint, $base, $maxPerTint);

            if ($most < 1) {
                continue;
            }

            foreach (array_unique([1, $most]) as $cans) {
                $best = min($best, $this->scoreOf(
                    $base,
                    [['ingredient' => $tint, 'count' => $cans]],
                    $targetLab,
                ));
            }
        }

        return $best;
    }

    /**
     * Tints that move this base TOWARD the target, best first, capped.
     *
     * THE WEAKER OF THE TWO PRUNES, and it is worth being explicit about why
     * rather than discovering it later:
     *
     *  - The mix path is curved. K/S mixing is linear in K/S, but the
     *    inversion back to reflectance and the map to Lab are not, so a
     *    direction measured at one pint does not describe where four land.
     *  - Two tints do not decompose. This scores each tint ALONE and the
     *    enumeration then pairs them; a tint that pulls hue hard toward the
     *    target while dragging lightness the wrong way is exactly the
     *    ingredient a second tint exists to compensate for.
     *  - The geometry is not the scoring metric. Projection is Euclidean in
     *    Lab; ranking is ΔE2000, which reweights L, C and H and rotates hue.
     *
     * Kept because it is cheap and the regret harness says what it costs. If
     * that number is unacceptable this is the first thing to replace — with a
     * branch-and-bound bound built on the convexity of K/S mixing, which would
     * preserve the optimum by construction.
     *
     * @return MixIngredient[]
     */
    private function rankedTints(
        MixCandidates $candidates,
        MixIngredient $base,
        array $targetLab,
        ?int $limit = null,
    ): array {
        $residual = [
            'l' => $targetLab['l'] - $base->lab['l'],
            'a' => $targetLab['a'] - $base->lab['a'],
            'b' => $targetLab['b'] - $base->lab['b'],
        ];

        $magnitude = sqrt($residual['l'] ** 2 + $residual['a'] ** 2 + $residual['b'] ** 2);

        // The base already IS the target. Nothing to correct toward, and any
        // ranking of tints here would be noise.
        if ($magnitude < 1e-9) {
            return [];
        }

        $scored = [];

        foreach ($candidates->tints as $tint) {
            $projection = (
                ($tint->lab['l'] - $base->lab['l']) * $residual['l']
                + ($tint->lab['a'] - $base->lab['a']) * $residual['a']
                + ($tint->lab['b'] - $base->lab['b']) * $residual['b']
            ) / $magnitude;

            if ($projection <= 0) {
                continue;
            }

            $scored[] = ['tint' => $tint, 'projection' => $projection];
        }

        usort($scored, fn ($a, $b) => $b['projection'] <=> $a['projection']
            ?: $a['tint']->variantId <=> $b['tint']->variantId);

        $cap = $limit ?? $this->tintCap ?? (int) config('paint.reachable.tint_cap');

        return array_column(array_slice($scored, 0, $cap), 'tint');
    }

    // -------------------------------------------------------
    // The recipe space
    // -------------------------------------------------------

    /**
     * Every recipe allowed from this base and these tints.
     *
     * The base count is always 1. Two base cans plus two pints is the same
     * RATIO as one and one, so it predicts the same colour — doubling it
     * doubles the search for no new colours. How much paint to buy is the
     * customer's business at the bench, not the solver's.
     *
     * @param  MixIngredient[]  $tints
     * @return iterable<array<int,array{ingredient:MixIngredient,count:int}>>
     */
    private function recipesFor(MixIngredient $base, array $tints): iterable
    {
        $maxPerTint = $this->maxPerTint ?? (int) config('paint.reachable.max_per_tint');
        $maxDistinct = $this->maxDistinctTints ?? (int) config('paint.reachable.max_distinct_tints');

        yield [];

        if ($maxDistinct < 1) {
            return;
        }

        $count = count($tints);

        for ($i = 0; $i < $count; $i++) {
            $first = $tints[$i];
            $firstMax = $this->affordableCans($first, $base, $maxPerTint);

            for ($n = 1; $n <= $firstMax; $n++) {
                yield [['ingredient' => $first, 'count' => $n]];

                if ($maxDistinct < 2) {
                    continue;
                }

                for ($j = $i + 1; $j < $count; $j++) {
                    $second = $tints[$j];
                    $secondMax = $this->affordableCans($second, $base, $maxPerTint, $first, $n);

                    for ($m = 1; $m <= $secondMax; $m++) {
                        yield [
                            ['ingredient' => $first, 'count' => $n],
                            ['ingredient' => $second, 'count' => $m],
                        ];
                    }
                }
            }
        }
    }

    /**
     * Cans of this tint the shelf can actually supply, given what the rest of
     * the recipe already claims.
     *
     * Stock is checked on TOTAL DEMAND PER VARIANT, not per line: a variant
     * used as the base and again as a tint comes off one shelf. Without this
     * the solver proposes recipes the cart then refuses — after the customer
     * has approved the colour.
     */
    private function affordableCans(
        MixIngredient $tint,
        MixIngredient $base,
        int $maxPerTint,
        ?MixIngredient $alsoUsed = null,
        int $alsoUsedCount = 0,
    ): int {
        $claimed = $tint->variantId === $base->variantId ? 1 : 0;

        if ($alsoUsed !== null && $alsoUsed->variantId === $tint->variantId) {
            $claimed += $alsoUsedCount;
        }

        return max(0, min($maxPerTint, $tint->stock - $claimed));
    }

    // -------------------------------------------------------
    // Scoring and selection
    // -------------------------------------------------------

    /**
     * The predicted colour of a recipe.
     *
     * Goes through ColorService::mix() rather than reimplementing the maths
     * inline. A faster path is available — the per-channel K/S of each
     * ingredient could be precomputed once — but a third implementation of
     * Kubelka-Munk (after ColorService and lib/paintMix.js) is exactly the
     * drift this project keeps testing for. If the search ever needs the
     * speed, the fast path belongs INSIDE ColorService as a second entry
     * point, not out here.
     *
     * @param  array<int,array{ingredient:MixIngredient,count:int}>  $tints
     */
    private function predict(MixIngredient $base, array $tints): string
    {
        $components = [$base->component(1, asBase: true)];

        foreach ($tints as $tint) {
            $components[] = $tint['ingredient']->component($tint['count']);
        }

        // Counted HERE, not in the main loop, so the base-ranking lookahead
        // shows up in the cost of a solve instead of hiding inside it.
        $this->evaluated++;

        return ColorService::mix($components) ?? $base->hex;
    }

    /** @param array<int,array{ingredient:MixIngredient,count:int}> $tints */
    private function scoreOf(MixIngredient $base, array $tints, array $targetLab): float
    {
        $hex = $this->predict($base, $tints);

        $lab = $this->labCache[$hex] ??= ColorService::hexToLab($hex);

        return ColorService::deltaE2000($lab, $targetLab);
    }

    /**
     * Among recipes indistinguishable from the best, the cheapest wins.
     *
     * Fewest cans first, then price. Adding a fourth pint to gain 0.3 ΔE
     * charges real money for a difference nobody can see — and one the
     * prediction cannot honestly resolve while tint_strength is uncalibrated.
     *
     * Ordering is made total (delta, then ids) so the same catalogue and the
     * same target always produce the same recipe; a solver that returned
     * either of two equal answers would make every regression test flaky.
     */
    private function pickCheapest(array $shortlist, float $searchedMin, float $tieTolerance): array
    {
        $eligible = array_values(array_filter(
            $shortlist,
            fn (array $entry) => $entry['delta'] <= $searchedMin + $tieTolerance,
        ));

        usort($eligible, function (array $a, array $b) {
            $cansA = 1 + array_sum(array_column($a['tints'], 'count'));
            $cansB = 1 + array_sum(array_column($b['tints'], 'count'));

            $priceA = $this->priceOf($a);
            $priceB = $this->priceOf($b);

            return $cansA <=> $cansB
                ?: $priceA <=> $priceB
                ?: $a['delta'] <=> $b['delta']
                ?: $a['base']->variantId <=> $b['base']->variantId
                ?: $this->tintSignature($a) <=> $this->tintSignature($b);
        });

        return $eligible[0];
    }

    private function priceOf(array $entry): float
    {
        $price = $entry['base']->price;

        foreach ($entry['tints'] as $tint) {
            $price += $tint['ingredient']->price * $tint['count'];
        }

        return $price;
    }

    private function tintSignature(array $entry): string
    {
        return implode(',', array_map(
            fn (array $tint) => $tint['ingredient']->variantId.'x'.$tint['count'],
            $entry['tints'],
        ));
    }
}
