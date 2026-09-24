<?php

namespace App\Services;

/**
 * Proposes a tint recipe for a target colour: how many ml of which colorant
 * presets to add to a given base can.
 *
 * The problem is continuous (amounts, not whole pints), so the search is:
 *
 *   1. START. Single-constant Kubelka-Munk mixes LINEARLY in K/S:
 *
 *        K/S_mix = (V·K/S_base + Σ x_j·s_j·K/S_j) / (V + Σ x_j)
 *
 *      Setting K/S_mix = K/S_target and multiplying out gives, per channel,
 *
 *        Σ x_j·(s_j·K/S_j − K/S_target) = V·(K/S_target − K/S_base)
 *
 *      — three linear equations in the tint volumes x_j. For every set of up
 *      to `maxTints` presets that is a tiny least-squares problem with a
 *      closed-form answer. A set whose answer asks for a negative amount is
 *      skipped: some smaller set, also tried, does the job without it.
 *
 *   2. SNAP. Each amount is rounded to its preset's step_ml and the total
 *      clipped to the can's cap, because that is the only recipe the customer
 *      can actually buy. Rounding 3.4 ml of black to 3 ml in a litre is a
 *      visible change, which is why the snap happens INSIDE the search.
 *
 *   3. REFINE. The best few snapped starts are walked downhill on the real
 *      measure — ΔE2000 between the target and the predicted HEX, exactly what
 *      the bench shows — one or five steps of one colorant at a time.
 *
 * The ΔE reported is always that of the snapped, refined recipe: the one that
 * goes in the cart. Nothing here is proven optimal. The shortlist and the move
 * set are a heuristic whose cost is MEASURED against an exhaustive search in
 * tests/Unit/TintSolverRegretTest.php; a failing bound means fix the search,
 * not the constant. See MIXING.md, "Solver".
 */
final class TintSolver
{
    /** @var array<int,array{id:int,name:string,hex:string,step:float,strength:float,ks:array{0:float,1:float,2:float}}> */
    private array $tints;

    /** Evaluations memoised within one base solve. */
    private array $memo = [];

    private string $target;

    /**
     * @param  array<int,array{id:int,name:string,hex:string,step:float,strength?:float}>  $tints
     * @param  int  $maxTints   distinct colorants a proposal may use
     * @param  int  $shortlist  snapped starts that get refined
     */
    public function __construct(
        array $tints,
        private readonly int $maxTints = 3,
        private readonly int $shortlist = 8,
    ) {
        $this->tints = [];

        foreach (array_values($tints) as $t) {
            $hex = ColorService::normalizeHex($t['hex']);

            if ($hex === null || (float) $t['step'] <= 0) {
                continue;
            }

            $strength = (float) ($t['strength'] ?? 1.0);
            $ks = self::ks($hex);

            $this->tints[] = [
                'id' => (int) $t['id'],
                'name' => (string) ($t['name'] ?? ''),
                'hex' => $hex,
                'step' => (float) $t['step'],
                'strength' => $strength,
                'ks' => [$ks[0] * $strength, $ks[1] * $strength, $ks[2] * $strength],
            ];
        }
    }

    /**
     * The best recipe this search finds for one base can.
     *
     * @param  array{hex:string,liters:float,cap:float,response?:float}  $base
     *         cap = max ml of colorant; response = the base type's tint_response
     * @return array{recipe:array<int,array{tint_color_id:int,name:string,hex:string,ml:float}>,hex:string,delta_e:float}|null
     */
    public function solve(string $targetHex, array $base): ?array
    {
        $target = ColorService::normalizeHex($targetHex);
        $baseHex = ColorService::normalizeHex($base['hex'] ?? null);
        $liters = (float) ($base['liters'] ?? 0);
        $cap = (float) ($base['cap'] ?? 0);

        if ($target === null || $baseHex === null || $liters <= 0) {
            return null;
        }

        $this->target = $target;
        $this->memo = [];

        $ctx = ['hex' => $baseHex, 'liters' => $liters, 'cap' => $cap, 'response' => (float) ($base['response'] ?? 1.0)];

        // Counts are in STEPS per tint index; a recipe is [index => steps].
        $starts = [[]];   // the base on its own is always a candidate

        foreach ($this->starts($ctx) as $start) {
            $starts[] = $start;
        }

        $scored = [];

        foreach ($starts as $counts) {
            $scored[] = ['counts' => $counts, 'de' => $this->score($ctx, $counts)];
        }

        usort($scored, fn ($a, $b) => $a['de'] <=> $b['de']);

        $best = $scored[0];

        foreach (array_slice($scored, 0, $this->shortlist) as $candidate) {
            $refined = $this->refine($ctx, $candidate['counts'], $candidate['de']);

            if ($refined['de'] < $best['de']) {
                $best = $refined;
            }
        }

        return $this->shape($ctx, $best['counts'], $best['de']);
    }

    // -------------------------------------------------------
    // 1 + 2: least-squares starts, snapped to steps and the cap
    // -------------------------------------------------------

    /** @return iterable<array<int,int>> */
    private function starts(array $ctx): iterable
    {
        $kt = self::ks($this->target);
        $kb = self::ks($ctx['hex']);
        $n = count($this->tints);

        foreach ($this->subsets($n, min($this->maxTints, $n)) as $subset) {
            $x = $this->leastSquares($subset, $kt, $kb, $ctx['liters'], $ctx['response']);

            if ($x === null) {
                continue;
            }

            $counts = [];

            foreach ($subset as $k => $i) {
                if ($x[$k] <= 0) {
                    continue 2;   // a smaller subset covers this without the negative tint
                }

                $counts[$i] = max(1, (int) round(($x[$k] * 1000) / $this->tints[$i]['step']));
            }

            yield $this->clipToCap($ctx, $counts);
        }
    }

    /**
     * Solve min ‖A·x − b‖ for the tint volumes x (litres), where
     * A[c][j] = s_j·K/S_j,c − K/S_t,c and b[c] = V·(K/S_t,c − K/S_b,c).
     *
     * @param  int[]  $subset
     * @return float[]|null  null when the system is singular
     */
    private function leastSquares(array $subset, array $kt, array $kb, float $liters, float $response = 1.0): ?array
    {
        $k = count($subset);
        $a = [];
        $b = [];

        for ($c = 0; $c < 3; $c++) {
            foreach ($subset as $j => $i) {
                $a[$c][$j] = $this->tints[$i]['ks'][$c] * $response - $kt[$c];
            }
            $b[$c] = $liters * ($kt[$c] - $kb[$c]);
        }

        // Normal equations: (AᵀA)·x = Aᵀb, at most 3×3.
        $m = [];
        $v = [];

        for ($r = 0; $r < $k; $r++) {
            $v[$r] = 0.0;
            for ($c = 0; $c < 3; $c++) {
                $v[$r] += $a[$c][$r] * $b[$c];
            }
            for ($s = 0; $s < $k; $s++) {
                $m[$r][$s] = 0.0;
                for ($c = 0; $c < 3; $c++) {
                    $m[$r][$s] += $a[$c][$r] * $a[$c][$s];
                }
            }
        }

        return self::gauss($m, $v);
    }

    /** Lower the largest amount a step at a time until the recipe fits the can. */
    private function clipToCap(array $ctx, array $counts): array
    {
        while ($counts !== [] && $this->ml($counts) > $ctx['cap'] + 1e-9) {
            // Scale everything back in proportion first — it keeps the hue the
            // start aimed for — and step down one at a time only at the end.
            $ratio = $ctx['cap'] / $this->ml($counts);

            if ($ratio < 0.95) {
                $scaled = array_map(fn ($n) => max(1, (int) floor($n * $ratio)), $counts);

                // Scaling cannot go below one step each; when it changes
                // nothing, fall through or this would loop forever.
                if ($scaled !== $counts) {
                    $counts = $scaled;

                    continue;
                }
            }

            $largest = array_keys($counts, max($counts))[0];
            $counts[$largest]--;

            if ($counts[$largest] <= 0) {
                unset($counts[$largest]);
            }
        }

        return $counts;
    }

    // -------------------------------------------------------
    // 3: refine on the real measure
    // -------------------------------------------------------

    /**
     * Best-improvement descent. The moves:
     *
     *   more / less   ±1 or ±5 steps of one colorant (down to zero removes it)
     *   transfer      one colorant up, another down by the same VOLUME
     *   bring in      a colorant not yet in the recipe, room made for it by
     *                 taking from the largest one when the can is full
     *
     * Transfers exist because of the cap. For a target no recipe reaches, the
     * best answer sits ON the cap, where every "more" is refused and every
     * "less" is worse — a descent with only the first two moves stops there,
     * which measured as 2.35 ΔE of regret. Trading one colorant for another at
     * a constant total walks along the cap instead.
     */
    private function refine(array $ctx, array $counts, float $de): array
    {
        for ($iteration = 0; $iteration < 400; $iteration++) {
            $bestMove = null;
            $bestDe = $de;

            foreach ($this->moves($counts) as $next) {
                if ($this->ml($next) > $ctx['cap'] + 1e-9) {
                    continue;
                }

                $score = $this->score($ctx, $next);

                if ($score < $bestDe - 1e-12) {
                    $bestDe = $score;
                    $bestMove = $next;
                }
            }

            if ($bestMove === null) {
                break;
            }

            $counts = $bestMove;
            $de = $bestDe;
        }

        return ['counts' => $counts, 'de' => $de];
    }

    /** @return iterable<array<int,int>> */
    private function moves(array $counts): iterable
    {
        $set = fn (array $c, int $i, int $n) => $n <= 0 ? array_diff_key($c, [$i => true]) : array_replace($c, [$i => $n]);

        foreach ($counts as $i => $n) {
            foreach ([1, -1, 5, -5] as $d) {
                yield $set($counts, $i, $n + $d);
            }

            foreach ($counts as $j => $m) {
                if ($i === $j) {
                    continue;
                }

                foreach ([1, 5] as $up) {
                    // Take the same VOLUME from j as is given to i.
                    $down = (int) ceil(($up * $this->tints[$i]['step']) / $this->tints[$j]['step'] - 1e-9);
                    yield $set($set($counts, $i, $n + $up), $j, $m - $down);
                }
            }
        }

        if (count($counts) < $this->maxTints) {
            $largest = $counts === [] ? null : array_keys($counts, max($counts))[0];

            foreach ($this->tints as $k => $t) {
                if (isset($counts[$k])) {
                    continue;
                }

                yield $counts + [$k => 1];

                if ($largest !== null) {
                    $down = (int) ceil($t['step'] / $this->tints[$largest]['step'] - 1e-9);
                    yield $set($counts, $largest, $counts[$largest] - $down) + [$k => 1];
                }
            }
        }
    }

    // -------------------------------------------------------
    // Evaluation — the same maths the cart and the bench use
    // -------------------------------------------------------

    private function score(array $ctx, array $counts): float
    {
        ksort($counts);
        $key = json_encode($counts);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $hex = $this->predict($ctx, $counts);

        return $this->memo[$key] = $hex === null ? INF : (float) ColorService::distance($this->target, $hex);
    }

    private function predict(array $ctx, array $counts): ?string
    {
        $components = [['hex' => $ctx['hex'], 'liters' => $ctx['liters'], 'strength' => 1.0]];

        foreach ($counts as $i => $n) {
            $t = $this->tints[$i];
            $components[] = ['hex' => $t['hex'], 'liters' => $n * $t['step'] / 1000, 'strength' => $t['strength'] * $ctx['response']];
        }

        return ColorService::mix($components);
    }

    private function ml(array $counts): float
    {
        $total = 0.0;

        foreach ($counts as $i => $n) {
            $total += $n * $this->tints[$i]['step'];
        }

        return round($total, 1);
    }

    private function shape(array $ctx, array $counts, float $de): array
    {
        $recipe = [];

        foreach ($counts as $i => $n) {
            $t = $this->tints[$i];
            $recipe[] = [
                'tint_color_id' => $t['id'],
                'name' => $t['name'],
                'hex' => $t['hex'],
                'ml' => round($n * $t['step'], 1),
            ];
        }

        usort($recipe, fn ($a, $b) => $b['ml'] <=> $a['ml']);

        return [
            'recipe' => $recipe,
            'hex' => $this->predict($ctx, $counts),
            'delta_e' => $de,
        ];
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /** K/S per channel, with the same clamp as ColorService::mix(). */
    private static function ks(string $hex): array
    {
        $floor = (float) config('paint.mix.reflectance_floor');
        $ceil = (float) config('paint.mix.reflectance_ceil');

        return array_map(function ($v) use ($floor, $ceil) {
            $r = min($ceil, max($floor, $v / 255));

            return (1 - $r) ** 2 / (2 * $r);
        }, ColorService::hexToRgb($hex));
    }

    /** Every subset of 0..n-1 with 1..max members, smallest first. @return iterable<int[]> */
    private function subsets(int $n, int $max): iterable
    {
        for ($size = 1; $size <= $max; $size++) {
            yield from self::combinations(range(0, $n - 1), $size);
        }
    }

    private static function combinations(array $items, int $size, int $start = 0, array $prefix = []): iterable
    {
        if (count($prefix) === $size) {
            yield $prefix;

            return;
        }

        for ($i = $start; $i < count($items); $i++) {
            yield from self::combinations($items, $size, $i + 1, [...$prefix, $items[$i]]);
        }
    }

    /** Gaussian elimination with partial pivoting. Null when singular. */
    private static function gauss(array $m, array $v): ?array
    {
        $n = count($v);

        for ($col = 0; $col < $n; $col++) {
            $pivot = $col;
            for ($r = $col + 1; $r < $n; $r++) {
                if (abs($m[$r][$col]) > abs($m[$pivot][$col])) {
                    $pivot = $r;
                }
            }

            if (abs($m[$pivot][$col]) < 1e-14) {
                return null;
            }

            [$m[$col], $m[$pivot]] = [$m[$pivot], $m[$col]];
            [$v[$col], $v[$pivot]] = [$v[$pivot], $v[$col]];

            for ($r = $col + 1; $r < $n; $r++) {
                $f = $m[$r][$col] / $m[$col][$col];
                for ($c = $col; $c < $n; $c++) {
                    $m[$r][$c] -= $f * $m[$col][$c];
                }
                $v[$r] -= $f * $v[$col];
            }
        }

        $x = array_fill(0, $n, 0.0);

        for ($r = $n - 1; $r >= 0; $r--) {
            $sum = $v[$r];
            for ($c = $r + 1; $c < $n; $c++) {
                $sum -= $m[$r][$c] * $x[$c];
            }
            $x[$r] = $sum / $m[$r][$r];
        }

        return $x;
    }
}
