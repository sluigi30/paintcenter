<?php

namespace Tests\Unit;

use App\Services\ColorService;
use App\Services\TintSolver;
use Tests\TestCase;

/**
 * What TintSolver's shortcuts cost, measured rather than argued.
 *
 * The solver tries least-squares starts, snaps them to each preset's step and
 * walks the best few downhill. None of that is proven to find the best recipe,
 * so the honest claim is a number:
 *
 *     regret = ΔE(solver) − ΔE(exhaustive)
 *
 * The oracle below enumerates EVERY recipe on the same grid — whole steps of
 * each preset, at most two presets, total within the can's cap — and shares
 * nothing with the solver except the definition of the colour
 * (ColorService::mix) and of the distance (ColorService::distance), which is
 * exactly what both must agree on.
 *
 * The grid is kept small so the oracle can finish (a 1L can with a 16 ml cap,
 * five presets). The solver's behaviour does not depend on the grid's size,
 * only its search does. See MIXING.md, "Solver", and the rule from
 * REACHABILITY.md: a failing bound means fix the search, not the constant.
 */
class TintSolverRegretTest extends TestCase
{
    private const TINTS = [
        ['id' => 1, 'name' => 'Oxide Red',    'hex' => '#9B3A2A', 'step' => 1.0, 'strength' => 1.0],
        ['id' => 2, 'name' => 'Yellow Oxide', 'hex' => '#C9A227', 'step' => 2.0, 'strength' => 1.0],
        ['id' => 3, 'name' => 'Phthalo Blue', 'hex' => '#17357A', 'step' => 1.0, 'strength' => 1.0],
        ['id' => 4, 'name' => 'Carbon Black', 'hex' => '#1A1A1A', 'step' => 0.5, 'strength' => 1.0],
        ['id' => 5, 'name' => 'Phthalo Green', 'hex' => '#0B5E45', 'step' => 2.0, 'strength' => 1.0],
    ];

    private const BASES = [
        'white'  => ['hex' => '#FFFFFF', 'liters' => 1.0, 'cap' => 16.0],
        'pastel' => ['hex' => '#F4F2EC', 'liters' => 1.0, 'cap' => 16.0],
        // A deep base's tint_response multiplies every colorant's pull — the
        // search space is the same grid, the landscape over it much steeper.
        'deep'   => ['hex' => '#DDDAD0', 'liters' => 1.0, 'cap' => 16.0, 'response' => 2.5],
    ];

    private const TARGETS = 40;

    private static ?array $samples = null;

    /**
     * Holds BY CONSTRUCTION: the solver proposes recipes on the oracle's grid,
     * so the oracle can never come back worse. A negative regret is not good
     * news — it means the two disagree about which recipes are allowed (a step,
     * the cap, the tint count), and every other number here is then meaningless.
     */
    public function test_regret_is_never_negative(): void
    {
        foreach ($this->measure() as $s) {
            $this->assertGreaterThanOrEqual(-1e-9, $s['regret'],
                "Solver beat the exhaustive search on {$s['target']} ({$s['base']}): the two disagree about the recipe space.");
        }
    }

    /**
     * Measured 2026-09-24 over 80 (target, base) pairs: mean 0.011, p99 0.226,
     * worst 0.248 ΔE — every miss above 0.1 on a target nothing on the grid
     * reaches (ΔE 12–21 from the best recipe), where a quarter of a ΔE does not
     * change the band the customer reads. Re-measured 2026-09-25 with a deep
     * base (tint_response 2.5) added, 120 pairs: mean 0.013, p99 0.211, worst
     * 0.248. The bounds sit just above.
     *
     * The first version, without TRANSFER moves, measured mean 0.170, p99
     * 2.185, worst 2.350: on an unreachable target the best recipe sits on the
     * cap, where "more" is refused and "less" is worse, and the descent
     * stopped. See TintSolver::refine().
     */
    public function test_regret_stays_within_pinned_bounds(): void
    {
        $regrets = array_column($this->measure(), 'regret');
        sort($regrets);

        $worst = end($regrets);
        $p99 = $regrets[(int) floor(0.99 * (count($regrets) - 1))];
        $mean = array_sum($regrets) / count($regrets);

        fwrite(STDERR, sprintf(
            "\n  tint-solver regret over %d pairs — mean %.3f, p99 %.3f, worst %.3f ΔE\n",
            count($regrets), $mean, $p99, $worst,
        ));

        $this->assertLessThanOrEqual(self::BOUND_WORST, $worst, 'Worst-case regret has grown.');
        $this->assertLessThanOrEqual(self::BOUND_P99, $p99, 'p99 regret has grown.');
        $this->assertLessThanOrEqual(self::BOUND_MEAN, $mean, 'Mean regret has grown.');
    }

    private const BOUND_WORST = 0.35;
    private const BOUND_P99 = 0.30;
    private const BOUND_MEAN = 0.03;

    /**
     * What the solver reports is what the cart would get: its ΔE is the
     * distance to the hex its own recipe predicts, and that recipe is on the
     * grid — whole steps, within the cap, at most two presets.
     */
    public function test_the_reported_recipe_is_the_one_that_was_measured(): void
    {
        $solver = new TintSolver(self::TINTS, maxTints: 2);
        $steps = array_column(self::TINTS, 'step', 'id');
        $hexes = array_column(self::TINTS, 'hex', 'id');

        foreach ($this->targets() as $target) {
            foreach (self::BASES as $base) {
                $best = $solver->solve($target, $base);

                $this->assertLessThanOrEqual(2, count($best['recipe']));
                $this->assertLessThanOrEqual($base['cap'] + 1e-9, array_sum(array_column($best['recipe'], 'ml')));

                $components = [['hex' => $base['hex'], 'liters' => $base['liters'], 'strength' => 1.0]];

                foreach ($best['recipe'] as $row) {
                    $steps_ = $row['ml'] / $steps[$row['tint_color_id']];
                    $this->assertEqualsWithDelta(round($steps_), $steps_, 1e-9, 'Amounts must be whole steps.');
                    $components[] = ['hex' => $hexes[$row['tint_color_id']], 'liters' => $row['ml'] / 1000, 'strength' => $base['response'] ?? 1.0];
                }

                $this->assertSame(ColorService::mix($components), $best['hex']);
                $this->assertEqualsWithDelta(ColorService::distance($target, $best['hex']), $best['delta_e'], 1e-9);
            }
        }
    }

    public function test_a_colour_the_base_already_is_needs_no_colorant(): void
    {
        $best = (new TintSolver(self::TINTS))->solve('#FFFFFF', self::BASES['white']);

        $this->assertSame([], $best['recipe']);
        $this->assertEqualsWithDelta(0.0, $best['delta_e'], 1e-9);
    }

    public function test_it_never_exceeds_a_cap_smaller_than_one_step_of_everything(): void
    {
        // A pathological can: less room than a step of most presets. The
        // search must still terminate and still respect it.
        $best = (new TintSolver(self::TINTS))->solve('#20304A', ['hex' => '#FFFFFF', 'liters' => 1.0, 'cap' => 1.0]);

        $this->assertLessThanOrEqual(1.0, array_sum(array_column($best['recipe'], 'ml')));
    }

    // -------------------------------------------------------

    private function measure(): array
    {
        if (self::$samples !== null) {
            return self::$samples;
        }

        $solver = new TintSolver(self::TINTS, maxTints: 2);
        $samples = [];

        foreach ($this->targets() as $target) {
            foreach (self::BASES as $name => $base) {
                $found = $solver->solve($target, $base)['delta_e'];
                $exact = $this->exhaustive($target, $base);

                $samples[] = ['target' => $target, 'base' => $name, 'regret' => $found - $exact];
            }
        }

        return self::$samples = $samples;
    }

    /**
     * Half the targets are colours a grid recipe makes exactly (so the oracle's
     * answer is ~0 and any regret is the solver missing a known recipe); half
     * are arbitrary light-to-mid colours, most of which no recipe reaches.
     */
    private function targets(): array
    {
        mt_srand(20260924);
        $targets = [];

        for ($i = 0; $i < self::TARGETS / 2; $i++) {
            $a = self::TINTS[mt_rand(0, 4)];
            $b = self::TINTS[mt_rand(0, 4)];
            $na = mt_rand(1, 8);
            $nb = mt_rand(0, 4);

            $targets[] = ColorService::mix([
                ['hex' => '#FFFFFF', 'liters' => 1.0, 'strength' => 1.0],
                ['hex' => $a['hex'], 'liters' => $na * $a['step'] / 1000, 'strength' => 1.0],
                ['hex' => $b['hex'], 'liters' => $nb * $b['step'] / 1000, 'strength' => 1.0],
            ]);
        }

        for ($i = 0; $i < self::TARGETS / 2; $i++) {
            $targets[] = sprintf('#%02X%02X%02X', mt_rand(120, 250), mt_rand(120, 250), mt_rand(120, 250));
        }

        return $targets;
    }

    /** Every recipe on the grid: none, one preset, or two, within the cap. */
    private function exhaustive(string $target, array $base): float
    {
        $best = ColorService::distance($target, $base['hex']);
        $levels = [];

        foreach (self::TINTS as $i => $t) {
            $levels[$i] = (int) floor($base['cap'] / $t['step'] + 1e-9);
        }

        $eval = function (array $parts) use ($target, $base) {
            $components = [['hex' => $base['hex'], 'liters' => $base['liters'], 'strength' => 1.0]];

            foreach ($parts as [$t, $n]) {
                $components[] = ['hex' => $t['hex'], 'liters' => $n * $t['step'] / 1000, 'strength' => $base['response'] ?? 1.0];
            }

            return ColorService::distance($target, ColorService::mix($components));
        };

        $count = count(self::TINTS);

        for ($i = 0; $i < $count; $i++) {
            $ti = self::TINTS[$i];

            for ($a = 1; $a <= $levels[$i]; $a++) {
                $best = min($best, $eval([[$ti, $a]]));

                for ($j = $i + 1; $j < $count; $j++) {
                    $tj = self::TINTS[$j];

                    for ($b = 1; $b <= $levels[$j]; $b++) {
                        if ($a * $ti['step'] + $b * $tj['step'] > $base['cap'] + 1e-9) {
                            break;
                        }

                        $best = min($best, $eval([[$ti, $a], [$tj, $b]]));
                    }
                }
            }
        }

        return $best;
    }
}
