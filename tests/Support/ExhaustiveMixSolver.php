<?php

namespace Tests\Support;

use App\Services\ColorService;
use App\Services\MixCandidates;
use App\Services\MixIngredient;

/**
 * The oracle. Deliberately naive, test-only, and it never ships.
 *
 * It evaluates EVERY allowed recipe — no ranking, no caps, no projection
 * filter — so that MixSolver's pruning can be measured rather than argued
 * about. Its only job is to be obviously correct, which is why it is written
 * as plain nested loops calling ColorService::mix() directly and does not
 * share a single line of search code with the thing it checks. A shared helper
 * would let one bug pass both.
 *
 * It is NOT a fallback. If the production solver is too slow or too lossy the
 * answer is a better solver, not shipping this: on a real catalogue it is
 * hundreds of thousands of mixes per target.
 *
 * The recipe space it enumerates must match MixSolver's exactly:
 *   - one base can, always count 1
 *   - at most MAX_DISTINCT_TINTS distinct tints
 *   - 1..maxPerTint cans of each, bounded by that variant's stock
 *   - stock counted on TOTAL demand per variant, base included
 *
 * When they drift, regret goes negative — the pruned solver "beating" an
 * exhaustive one is arithmetically impossible over a subset, so that
 * assertion is what catches the drift. See REACHABILITY.md.
 */
class ExhaustiveMixSolver
{
    public function __construct(
        private readonly int $maxPerTint = 4,
        private readonly int $maxDistinctTints = 2,
    ) {}

    /**
     * The best distance achievable over the whole space, and the recipe that
     * reaches it. Returns null when nothing can be poured at all.
     *
     * Deliberately returns the MINIMUM, with no tie-break: the tie-break is a
     * product decision belonging to the production solver, and an oracle that
     * applied it too would be unable to measure what it costs.
     *
     * @return array{delta:float,hex:string,base:MixIngredient,tints:array,evaluated:int}|null
     */
    public function solve(string $targetHex, MixCandidates $candidates): ?array
    {
        $targetLab = ColorService::hexToLab($targetHex);

        if ($targetLab === null || $candidates->isEmpty()) {
            return null;
        }

        $best = null;
        $evaluated = 0;

        foreach ($candidates->bases as $base) {
            foreach ($this->everyRecipe($base, $candidates->tints) as $tints) {
                $components = [$base->component(1, asBase: true)];

                foreach ($tints as $tint) {
                    $components[] = $tint['ingredient']->component($tint['count']);
                }

                $hex = ColorService::mix($components) ?? $base->hex;
                $delta = ColorService::deltaE2000(ColorService::hexToLab($hex), $targetLab);
                $evaluated++;

                if ($best === null || $delta < $best['delta']) {
                    $best = [
                        'delta' => $delta,
                        'hex' => $hex,
                        'base' => $base,
                        'tints' => $tints,
                    ];
                }
            }
        }

        if ($best === null) {
            return null;
        }

        return $best + ['evaluated' => $evaluated];
    }

    /**
     * @param  MixIngredient[]  $tints
     * @return iterable<array<int,array{ingredient:MixIngredient,count:int}>>
     */
    private function everyRecipe(MixIngredient $base, array $tints): iterable
    {
        yield [];

        if ($this->maxDistinctTints < 1) {
            return;
        }

        $count = count($tints);

        for ($i = 0; $i < $count; $i++) {
            $first = $tints[$i];

            for ($n = 1; $n <= $this->maxPerTint; $n++) {
                if (! $this->inStock($first, $n, $base)) {
                    break;
                }

                yield [['ingredient' => $first, 'count' => $n]];

                if ($this->maxDistinctTints < 2) {
                    continue;
                }

                for ($j = $i + 1; $j < $count; $j++) {
                    $second = $tints[$j];

                    for ($m = 1; $m <= $this->maxPerTint; $m++) {
                        if (! $this->inStock($second, $m, $base, $first, $n)) {
                            break;
                        }

                        yield [
                            ['ingredient' => $first, 'count' => $n],
                            ['ingredient' => $second, 'count' => $m],
                        ];
                    }
                }
            }
        }
    }

    /** Total demand for this variant across the whole recipe, base included. */
    private function inStock(
        MixIngredient $tint,
        int $cans,
        MixIngredient $base,
        ?MixIngredient $alsoUsed = null,
        int $alsoUsedCount = 0,
    ): bool {
        $demand = $cans;

        if ($tint->variantId === $base->variantId) {
            $demand += 1;
        }

        if ($alsoUsed !== null && $alsoUsed->variantId === $tint->variantId) {
            $demand += $alsoUsedCount;
        }

        return $demand <= $tint->stock;
    }
}
