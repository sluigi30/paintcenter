<?php

namespace App\Console\Commands;

use App\Models\ProductVariant;
use App\Services\ColorService;
use Illuminate\Console\Command;

/**
 * Writes the mix-parity fixture the mobile app checks itself against.
 *
 * The phone predicts the mixed colour locally so the swatch can move under the
 * customer's finger, but the SERVER's answer is the one that reaches the cart,
 * the order and the counter. If the two implementations drift, the customer
 * approves one colour on the bench and buys another, and nothing anywhere
 * raises an error.
 *
 * So the values are generated here, from the real ColorService, and the mobile
 * test asserts its own maths reproduces them. Regenerate whenever the mixing
 * maths or config/paint.php changes — and expect the mobile test to fail until
 * lib/paintMix.js is brought back into line, which is the entire point.
 *
 *   php artisan mix:parity-fixture
 *
 * See MIXING.md.
 */
class MixParityFixture extends Command
{
    protected $signature = 'mix:parity-fixture
                            {--path= : Where to write it (defaults to the sibling mobile repo)}';

    protected $description = 'Generate the mix-prediction parity fixture for the mobile app';

    /** A pint, as config/paint.php defines it. */
    private const P = 0.473;

    public function handle(): int
    {
        $path = $this->option('path')
            ?: base_path('../paintcenter-mobile/lib/__tests__/mix-parity.fixture.json');

        $fixture = [
            '_generated_by' => 'php artisan mix:parity-fixture',
            '_source' => 'App\Services\ColorService::mix',
            '_warning' => 'Generated. Do not hand-edit — regenerate instead.',
            'config' => [
                'pint_liters' => config('paint.mix.pint_liters'),
                'reflectance_floor' => config('paint.mix.reflectance_floor'),
                'reflectance_ceil' => config('paint.mix.reflectance_ceil'),
                // The words the customer reads about a match are chosen by
                // these, on both sides.
                'bands' => config('paint.mix.bands'),
            ],
            'mixes' => [],
            'liters' => [],
            'distances' => [],
        ];

        foreach ($this->cases() as $name => $components) {
            $fixture['mixes'][] = [
                'name' => $name,
                'components' => $components,
                'expected' => ColorService::mix($components),
            ];
        }

        // The volume parser decides the recipe's proportions, so it has to
        // agree too — "Set of 3" read as three litres on one side and as
        // nothing on the other is a different colour.
        foreach ([
            '4L', '1L', '2.5L', '4 L', '500ml', '1 gal', 'Pint', 'pt', '1 pt',
            'Set of 3', '3 pieces', 'Large', '', '4', 'Pinto',
        ] as $size) {
            $fixture['liters'][] = [
                'size_volume' => $size,
                'expected' => (new ProductVariant(['size_volume' => $size]))->liters,
            ];
        }

        // ΔE2000, which decides what the bench SAYS about a match against a
        // target. The phone has to reach the same number from the same two
        // hexes, or it tells the customer "close" where the server says "near".
        foreach ($this->distancePairs() as [$a, $b]) {
            $fixture['distances'][] = [
                'a' => $a,
                'b' => $b,
                'expected' => ColorService::distance($a, $b),
            ];
        }

        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info(sprintf(
            'Wrote %d mixes and %d sizes to %s',
            count($fixture['mixes']),
            count($fixture['liters']),
            realpath($path) ?: $path,
        ));

        return self::SUCCESS;
    }

    /**
     * Pairs for the ΔE2000 check: identical, near-neutral (where CIE76 fails
     * worst), the blue hue-rotation region, across the hue wrap, and far apart.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function distancePairs(): array
    {
        return [
            ['#FFFFFF', '#FFFFFF'],
            ['#808080', '#838080'],
            ['#7F7F7F', '#807F80'],
            ['#17357A', '#2244AA'],
            ['#3050C0', '#4060D0'],
            ['#CC2222', '#CC2233'],
            ['#FF0010', '#F00000'],
            ['#E75E5E', '#ED7878'],
            ['#F4F2EC', '#E6E4DC'],
            ['#000000', '#FFFFFF'],
            ['#1A1A1A', '#C9A227'],
            ['#B87355', '#C47A52'],
        ];
    }

    /**
     * Cases chosen to catch the ways the two implementations can drift:
     * the clamp, the rounding, the volume weighting, the strength lever, and
     * the single-component short circuit.
     *
     * @return array<string,array<int,array{hex:string,liters:float,strength:float}>>
     */
    private function cases(): array
    {
        $part = fn (string $hex, float $l, float $s = 1.0) => ['hex' => $hex, 'liters' => $l, 'strength' => $s];

        $cases = [
            // Identity — the short circuit, and the values the clamp would
            // otherwise nudge.
            'identity white' => [$part('#FFFFFF', 4)],
            'identity black' => [$part('#000000', 4)],
            'identity mid' => [$part('#808080', 1)],

            // The documented reach table.
            'reach 1 pint' => [$part('#FFFFFF', 4), $part('#CC2222', 1 * self::P)],
            'reach 2 pints' => [$part('#FFFFFF', 4), $part('#CC2222', 2 * self::P)],
            'reach 4 pints' => [$part('#FFFFFF', 4), $part('#CC2222', 4 * self::P)],
            'reach 8 pints' => [$part('#FFFFFF', 4), $part('#CC2222', 8 * self::P)],
            'reach 16 pints' => [$part('#FFFFFF', 4), $part('#CC2222', 16 * self::P)],

            // The floor, and the bias it guards.
            'pure black pint' => [$part('#FFFFFF', 4), $part('#000000', self::P)],
            'near black pint' => [$part('#FFFFFF', 4), $part('#1A1A1A', self::P)],

            // Strength, above and below 1.
            'strength 0.20' => [$part('#FFFFFF', 4), $part('#1A1A1A', self::P, 0.20)],
            'strength 0.50' => [$part('#FFFFFF', 4), $part('#1A1A1A', self::P, 0.50)],
            'strength 2.00' => [$part('#FFFFFF', 4), $part('#1A1A1A', self::P, 2.00)],
            'strength 9.99' => [$part('#FFFFFF', 4), $part('#CC2222', self::P, 9.99)],

            // Several colourants, and a saturated pair where the maths is
            // least forgiving.
            'three colours' => [$part('#FFFFFF', 4), $part('#CC2222', self::P), $part('#2244AA', 2 * self::P)],
            'blue and yellow' => [$part('#0000FF', 1), $part('#FFFF00', 1)],
            'beige plus blue' => [$part('#E8DCC8', 4), $part('#2244AA', self::P)],

            // A deep base lightened, which is the only route to a deep colour.
            'deep base tinted' => [$part('#26323C', 4), $part('#FFFFFF', 3 * self::P)],

            // Awkward volumes — rounding is where two languages part company.
            'lopsided ratio' => [$part('#FFFFFF', 16), $part('#CC2222', 0.001)],
            'tiny base' => [$part('#CC2222', 0.05), $part('#FFFFFF', 4)],
        ];

        // Tint recipes: MILLILITRES of colorant into litres of base. Ratios of
        // 0.1-6% by volume, far smaller than any pint mix, which is where a
        // float difference between the two languages would show first.
        $ml = fn (float $v) => $v / 1000;
        $cases += [
            'recipe 20ml oxide red in 4L' => [$part('#FFFFFF', 4), $part('#9B3A2A', $ml(20))],
            'recipe 0.5ml black in 1L' => [$part('#FFFFFF', 1), $part('#1A1A1A', $ml(0.5))],
            'recipe at the 60ml/L cap' => [$part('#FFFFFF', 4), $part('#17357A', $ml(240))],
            'recipe three colorants' => [$part('#F4F2EC', 4), $part('#9B3A2A', $ml(20)), $part('#C9A227', $ml(10)), $part('#1A1A1A', $ml(1.5))],
            'recipe deep base, strength 3' => [$part('#E6E4DC', 1), $part('#B3161C', $ml(90), 3.0)],
        ];

        // Every grey step through the clamp and back, which is where an
        // off-by-one in rounding shows up first.
        foreach ([0, 1, 2, 7, 64, 127, 128, 200, 253, 254, 255] as $v) {
            $hex = sprintf('#%02X%02X%02X', $v, $v, $v);
            $cases["grey {$v} plus white"] = [$part($hex, 1), $part('#FFFFFF', 1)];
        }

        return $cases;
    }
}
