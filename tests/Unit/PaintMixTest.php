<?php

namespace Tests\Unit;

use App\Models\ProductVariant;
use App\Services\ColorService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ColorService::mix() predicts what the counter will actually produce when it
 * pours the customer's recipe together. The customer buys against that swatch,
 * so it is pinned here — including the values that decide what is REACHABLE,
 * because a later "improvement" to the maths could silently change which
 * colours the shop appears to sell.
 *
 * Extends Tests\TestCase (not PHPUnit's) because the clamp and the pint size
 * live in config/paint.php and need the app booted.
 *
 * See MIXING.md.
 */
class PaintMixTest extends TestCase
{
    private const PINT = 0.473;

    private function part(string $hex, float $liters, float $strength = 1.0): array
    {
        return ['hex' => $hex, 'liters' => $liters, 'strength' => $strength];
    }

    // -------------------------------------------------------
    // The documented reach table
    // -------------------------------------------------------

    /**
     * Pints of #CC2222 into 4L of white. These exact values are quoted in
     * MIXING.md as the evidence that deep colours are UNREACHABLE by adding
     * pints — sixteen of them is still not the red you started with. If this
     * test changes, that argument has to be re-made, not just re-pasted.
     */
    public static function reachTable(): array
    {
        return [
            '1 pint'   => [1,  '#ED7878'],
            '2 pints'  => [2,  '#E75E5E'],
            '4 pints'  => [4,  '#E14848'],
            '8 pints'  => [8,  '#DA3838'],
            '16 pints' => [16, '#D52E2E'],
        ];
    }

    #[DataProvider('reachTable')]
    public function test_reach_table_is_stable(int $pints, string $expected): void
    {
        $this->assertSame($expected, ColorService::mix([
            $this->part('#FFFFFF', 4),
            $this->part('#CC2222', $pints * self::PINT),
        ]));
    }

    public function test_adding_pints_never_reaches_the_ingredient_colour(): void
    {
        // The asymptote is the point. Even at absurd volume the mix stays
        // lighter than the paint being poured in, because the base is still
        // there. Anything else means the volume weighting has broken.
        $ingredient = ColorService::hexToLab('#CC2222');
        $mixed      = ColorService::hexToLab(ColorService::mix([
            $this->part('#FFFFFF', 4),
            $this->part('#CC2222', 40 * self::PINT),
        ]));

        $this->assertGreaterThan(
            $ingredient['l'],
            $mixed['l'],
            'A mix containing white cannot be darker than the tint alone.'
        );
    }

    // -------------------------------------------------------
    // Why not a channel average
    // -------------------------------------------------------

    public function test_a_dark_tint_actually_darkens_the_mix(): void
    {
        // One black pint in 4L of white is ~10% of the volume. A naive RGB
        // average returns #E7E7E7 — visually still white, which is the whole
        // reason this is subtractive.
        $mixed = ColorService::mix([
            $this->part('#FFFFFF', 4),
            $this->part('#1A1A1A', self::PINT),
        ]);

        $this->assertSame('#696969', $mixed);
        $this->assertLessThan(
            60,
            ColorService::hexToLab($mixed)['l'],
            'A tenth of the volume in black must move the mix well off white.'
        );
    }

    // -------------------------------------------------------
    // Identity and degenerate input
    // -------------------------------------------------------

    /**
     * A base with no tints added yet must show its OWN colour, unshifted.
     * The reflectance clamp would otherwise move it by a rounding step, which
     * the customer sees as the swatch changing for no reason.
     */
    #[DataProvider('identityColors')]
    public function test_a_single_component_returns_itself(string $hex): void
    {
        $this->assertSame($hex, ColorService::mix([$this->part($hex, 4)]));
    }

    public static function identityColors(): array
    {
        return [
            'white'    => ['#FFFFFF'],
            'black'    => ['#000000'],
            'red'      => ['#CC2222'],
            'beige'    => ['#E8DCC8'],
            'mid grey' => ['#808080'],
        ];
    }

    public function test_pure_black_does_not_swallow_the_mix(): void
    {
        // #000000 as an ingredient is an IDEAL black of infinite absorption,
        // and one pint of it would drag any recipe to pure black. The
        // reflectance floor is what stops the prediction leaving physics.
        $this->assertSame('#252525', ColorService::mix([
            $this->part('#FFFFFF', 4),
            $this->part('#000000', self::PINT),
        ]));
    }

    #[DataProvider('unusableRecipes')]
    public function test_unusable_input_returns_null(array $components): void
    {
        $this->assertNull(ColorService::mix($components));
    }

    public static function unusableRecipes(): array
    {
        return [
            'nothing'        => [[]],
            'no hex on file' => [[['hex' => null, 'liters' => 4]]],
            'zero volume'    => [[['hex' => '#FFFFFF', 'liters' => 0]]],
            'negative'       => [[['hex' => '#FFFFFF', 'liters' => -4]]],
            'unparseable'    => [[['hex' => 'not a colour', 'liters' => 4]]],
        ];
    }

    public function test_a_component_with_no_hex_is_skipped_not_fatal(): void
    {
        // One variant missing a hex_code must not cost the customer the whole
        // recipe — the rest of it is still a mix.
        $this->assertSame(
            ColorService::mix([$this->part('#FFFFFF', 4), $this->part('#CC2222', self::PINT)]),
            ColorService::mix([
                $this->part('#FFFFFF', 4),
                ['hex' => null, 'liters' => 2],
                $this->part('#CC2222', self::PINT),
            ])
        );
    }

    // -------------------------------------------------------
    // Properties the recipe must obey
    // -------------------------------------------------------

    public function test_order_of_ingredients_does_not_change_the_result(): void
    {
        $a = ColorService::mix([
            $this->part('#FFFFFF', 4),
            $this->part('#CC2222', self::PINT),
            $this->part('#2244AA', self::PINT),
        ]);

        $b = ColorService::mix([
            $this->part('#2244AA', self::PINT),
            $this->part('#CC2222', self::PINT),
            $this->part('#FFFFFF', 4),
        ]);

        $this->assertSame($a, $b, 'Pouring order is not a property of the paint.');
    }

    public function test_only_the_ratio_matters_not_the_absolute_volume(): void
    {
        // Doubling every can gives twice as much of the same colour.
        $this->assertSame(
            ColorService::mix([$this->part('#FFFFFF', 4), $this->part('#CC2222', self::PINT)]),
            ColorService::mix([$this->part('#FFFFFF', 8), $this->part('#CC2222', 2 * self::PINT)])
        );
    }

    public function test_more_of_a_tint_moves_the_mix_further(): void
    {
        $lightness = fn (string $hex) => ColorService::hexToLab($hex)['l'];

        $one = $lightness(ColorService::mix([$this->part('#FFFFFF', 4), $this->part('#1A1A1A', self::PINT)]));
        $two = $lightness(ColorService::mix([$this->part('#FFFFFF', 4), $this->part('#1A1A1A', 2 * self::PINT)]));

        $this->assertLessThan($one, $two, 'A second black pint must darken it further.');
    }

    // -------------------------------------------------------
    // tint_strength — the calibration lever
    // -------------------------------------------------------

    public function test_strength_scales_how_hard_a_tint_pulls(): void
    {
        $lightness = fn (?string $hex) => ColorService::hexToLab($hex)['l'];

        $full = ColorService::mix([$this->part('#FFFFFF', 4), $this->part('#1A1A1A', self::PINT, 1.0)]);
        $weak = ColorService::mix([$this->part('#FFFFFF', 4), $this->part('#1A1A1A', self::PINT, 0.2)]);

        // Single-constant Kubelka-Munk over-predicts dark additions; a
        // strength below 1 is how the counter corrects that against a real
        // mix. Weaker must mean lighter, or the lever is wired backwards.
        $this->assertGreaterThan($lightness($full), $lightness($weak));
        $this->assertSame('#AAAAAA', $weak);
    }

    public function test_the_base_is_unaffected_by_its_own_strength(): void
    {
        // Documented rule: the base always mixes at strength 1.00. Its own
        // calibration factor must never be applied, or a recipe would shift
        // colour with no tints in it at all. The caller enforces this by
        // passing 1.0; this pins the consequence.
        $this->assertSame(
            '#FFFFFF',
            ColorService::mix([$this->part('#FFFFFF', 4, 1.0)]),
            'A lone base returns itself regardless of calibration.'
        );
    }

    // -------------------------------------------------------
    // Can volume — recipe weighting, never stock
    // -------------------------------------------------------

    #[DataProvider('canSizes')]
    public function test_liters_reads_the_can_size(?string $sizeVolume, ?float $expected): void
    {
        $variant = new ProductVariant(['size_volume' => $sizeVolume]);

        if ($expected === null) {
            $this->assertNull($variant->liters);

            return;
        }

        $this->assertEqualsWithDelta($expected, $variant->liters, 0.0001);
    }

    public static function canSizes(): array
    {
        return [
            '4L'          => ['4L',       4.0],
            '1L'          => ['1L',       1.0],
            'decimal'     => ['2.5L',     2.5],
            'spaced'      => ['4 L',      4.0],
            'millilitres' => ['500ml',    0.5],
            'gallon'      => ['1 gal',    3.785411784],
            // A pint is a can size, not a number — it carries no digits at all.
            'pint'        => ['Pint',     0.473],
            'pt'          => ['pt',       0.473],
            // Everything below has no measurable volume. "Set of 3" is the one
            // that matters: read as three litres it silently adds a base can's
            // worth of paint to the recipe.
            'set'         => ['Set of 3', null],
            'pieces'      => ['3 pieces', null],
            'unitless'    => ['4',        null],
            'words'       => ['Large',    null],
            'empty'       => ['',         null],
            'null'        => [null,       null],
        ];
    }
}
