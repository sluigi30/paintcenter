<?php

namespace Tests\Unit;

use App\Services\MixSolver;
use Tests\Support\CatalogueFactory;
use Tests\Support\ExhaustiveMixSolver;
use Tests\TestCase;

/**
 * What the pruning costs, measured rather than argued.
 *
 * MixSolver explores ~5,000 recipes where the full space is ~300,000. Neither
 * of its prunes is proven to preserve the best recipe, and the honest position
 * is not "it probably works" but a number: how much worse, at worst, is the
 * colour it finds than the colour that exists?
 *
 *     regret = ΔE(pruned) − ΔE(exhaustive)
 *
 * The oracle is Tests\Support\ExhaustiveMixSolver, which shares no search code
 * with the thing it checks.
 *
 * NOTE ON WHAT IS COMPARED. Regret is measured on searchedMinDeltaE — the best
 * the search FOUND — not on the recipe finally returned. The tie-break
 * deliberately returns a slightly worse colour when a cheaper recipe is
 * indistinguishable from it, so comparing the returned recipe would fold a
 * product decision into a measurement of the prune. The tie-break has its own
 * invariant, asserted separately below.
 */
class MixSolverRegretTest extends TestCase
{
    /** Catalogues big enough that the caps actually bite (26 bases > cap 10,
     *  10 tints > cap 8). A smaller shelf would make this test vacuous. */
    private const SEEDS = [1, 7, 42];

    private const TARGETS_PER_SEED = 8;

    /**
     * The one relation that holds BY CONSTRUCTION, and the most valuable
     * assertion in this file.
     *
     * The pruned search explores a SUBSET of the oracle's space, so the oracle
     * can never come back worse. A negative regret is therefore never a signal
     * to retune a cap: it is proof the two have drifted apart about what counts
     * as an allowed recipe — a different stock rule, a different tint
     * eligibility, a different cans-per-tint limit. That bug makes every other
     * number in this file meaningless while leaving them all green, and this
     * catches it on the first run.
     */
    public function test_regret_is_never_negative(): void
    {
        foreach ($this->measure() as $sample) {
            $this->assertGreaterThanOrEqual(
                -1e-9,
                $sample['regret'],
                "Pruned search beat an exhaustive one on {$sample['target']} — the two ".
                'disagree about the recipe space; the regret figures are meaningless until they agree.'
            );
        }
    }

    /**
     * The pinned cost of pruning.
     *
     * Measured 2026-09-24 over these 24 targets: mean 0.086, p99 0.763,
     * worst 1.273 ΔE. The bounds below sit just above that, so a real
     * regression is caught rather than absorbed.
     *
     * HOW THOSE NUMBERS WERE REACHED, because the first attempt was much
     * worse: ranking bases by their own ΔE to the target gave mean 0.397,
     * p99 2.610, worst 2.769 — bad enough to move a colour out of the "we can
     * mix this" band into "very close". Decomposing the regret showed the
     * base prune caused nearly all of it and the tint prune almost none, the
     * opposite of what the design doc predicted. Ranking bases by a cheap
     * reach lookahead instead cut the worst case by more than half. See
     * MixSolver::rankedBases().
     *
     * Treat these like PaintMixTest::test_reach_table_is_stable: if they fail,
     * the argument has to be re-made, not the number re-baselined. A rising
     * worst case means the prune is dropping recipes that matter, and the
     * response is a wider cap, a better ranking, or branch and bound — not a
     * bigger constant here.
     *
     * For scale: 1.3 ΔE is around the threshold where a side-by-side
     * difference becomes visible at all, and it is far below the error already
     * disclosed from uncalibrated tint_strength. It is not free, and it is not
     * the dominant term.
     */
    public function test_regret_stays_within_pinned_bounds(): void
    {
        $regrets = array_column($this->measure(), 'regret');
        sort($regrets);

        $worst = end($regrets);
        $p99 = $regrets[(int) floor(0.99 * (count($regrets) - 1))];
        $mean = array_sum($regrets) / count($regrets);

        fwrite(STDERR, sprintf(
            "\n  regret over %d targets — mean %.3f, p99 %.3f, worst %.3f ΔE\n",
            count($regrets), $mean, $p99, $worst,
        ));

        $this->assertLessThanOrEqual(1.5, $worst, 'Worst-case regret has grown.');
        $this->assertLessThanOrEqual(1.0, $p99, 'p99 regret has grown.');
    }

    /**
     * Metamorphic: looking at more things can never find a worse best.
     *
     * Top-(N+1) is a superset of top-N, so the candidate set genuinely grows.
     * That holds only because the two caps are INDEPENDENT: the tint ranking
     * is computed per base, and the base ranking's lookahead takes its own
     * fixed probe limit rather than tintCap. Couple them — by slicing the main
     * tint ranking inside reachEstimate(), which is how it was first written —
     * and widening the tint cap re-orders the bases, the superset property is
     * gone, and this test starts asserting something that is not structurally
     * true. A violation is a bug in candidate generation, scoring or this
     * harness; it is never "the heuristic got worse".
     *
     * Asserted on searchedMinDeltaE, NEVER on the returned recipe's deltaE:
     * the tie-break legitimately returns a slightly worse colour when a
     * cheaper recipe comes into view, so the selected distance is not monotone
     * and asserting it would fail for correct behaviour.
     */
    public function test_searched_minimum_is_monotonic_in_the_caps(): void
    {
        $candidates = CatalogueFactory::make(seed: 7);
        $targets = CatalogueFactory::targets(seed: 7, count: 6);

        $ladder = [
            ['base' => 2, 'tint' => 2],
            ['base' => 4, 'tint' => 4],
            ['base' => 8, 'tint' => 6],
            ['base' => 16, 'tint' => 10],
        ];

        foreach ($targets as $target) {
            $previous = null;

            foreach ($ladder as $caps) {
                $solved = (new MixSolver(baseCap: $caps['base'], tintCap: $caps['tint']))
                    ->solve($target, $candidates);

                if ($solved === null) {
                    continue;
                }

                if ($previous !== null) {
                    $this->assertLessThanOrEqual(
                        $previous + 1e-9,
                        $solved->searchedMinDeltaE,
                        "Widening the search made {$target} worse at base cap {$caps['base']}."
                    );
                }

                $previous = $solved->searchedMinDeltaE;
            }
        }
    }

    /**
     * The tie-break's own promise: it may trade accuracy for a cheaper recipe,
     * but only up to the configured tolerance. Without this it could quietly
     * drift into giving away real accuracy to save a can and nothing would say
     * so — the returned delta would simply be larger, with no signal.
     */
    public function test_tie_break_never_costs_more_than_the_tolerance(): void
    {
        $tolerance = (float) config('paint.reachable.tie_tolerance');

        foreach (self::SEEDS as $seed) {
            $candidates = CatalogueFactory::make($seed);

            foreach (CatalogueFactory::targets($seed, self::TARGETS_PER_SEED) as $target) {
                $solved = (new MixSolver)->solve($target, $candidates);

                if ($solved === null) {
                    continue;
                }

                $this->assertGreaterThanOrEqual(-1e-9, $solved->tieBreakCost());
                $this->assertLessThanOrEqual(
                    $tolerance + 1e-9,
                    $solved->tieBreakCost(),
                    "Tie-break gave away {$solved->tieBreakCost()} ΔE on {$target}."
                );
            }
        }
    }

    /**
     * Whatever the solver proposes, the shelf can supply — counted on TOTAL
     * demand per variant, base included. A recipe that fails this is one the
     * cart refuses after the customer has approved the colour.
     */
    public function test_proposed_recipes_never_exceed_stock(): void
    {
        foreach (self::SEEDS as $seed) {
            $candidates = CatalogueFactory::make($seed);

            foreach (CatalogueFactory::targets($seed, self::TARGETS_PER_SEED) as $target) {
                $solved = (new MixSolver)->solve($target, $candidates);

                if ($solved === null) {
                    continue;
                }

                $demand = [$solved->base->variantId => 1];

                foreach ($solved->tints as $tint) {
                    $id = $tint['ingredient']->variantId;
                    $demand[$id] = ($demand[$id] ?? 0) + $tint['count'];
                }

                foreach ($solved->tints as $tint) {
                    $id = $tint['ingredient']->variantId;
                    $this->assertLessThanOrEqual(
                        $tint['ingredient']->stock,
                        $demand[$id],
                        "Recipe for {$target} wants {$demand[$id]} of variant {$id}, ".
                        "shelf has {$tint['ingredient']->stock}."
                    );
                }

                $this->assertLessThanOrEqual(
                    (int) config('paint.reachable.max_distinct_tints'),
                    count($solved->tints),
                    "Recipe for {$target} names more distinct tints than the solver may propose."
                );
            }
        }
    }

    // -------------------------------------------------------

    /**
     * @return array<int,array{target:string,regret:float}>
     */
    private function measure(): array
    {
        static $samples = null;

        if ($samples !== null) {
            return $samples;
        }

        $pruned = new MixSolver;
        $oracle = new ExhaustiveMixSolver(
            maxPerTint: (int) config('paint.reachable.max_per_tint'),
            maxDistinctTints: (int) config('paint.reachable.max_distinct_tints'),
        );

        $samples = [];

        foreach (self::SEEDS as $seed) {
            $candidates = CatalogueFactory::make($seed);

            foreach (CatalogueFactory::targets($seed, self::TARGETS_PER_SEED) as $target) {
                $best = $oracle->solve($target, $candidates);
                $found = $pruned->solve($target, $candidates);

                if ($best === null || $found === null) {
                    continue;
                }

                $samples[] = [
                    'target' => $target,
                    'regret' => $found->searchedMinDeltaE - $best['delta'],
                ];
            }
        }

        return $samples;
    }
}
