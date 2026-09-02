<?php

namespace Tests\Unit;

use App\Services\ColorService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ColorService decides which base a custom colour is tinted into and whether
 * it can be mixed at all. Both answers reach the customer as money and as a
 * can of paint, so the maths is pinned down here.
 *
 * Extends Tests\TestCase (not PHPUnit's) because the thresholds live in
 * config/paint.php and need the app booted.
 */
class ColorServiceTest extends TestCase
{
    // -------------------------------------------------------
    // CIELAB conversion
    // -------------------------------------------------------

    /**
     * Published sRGB/D65 reference values. If these drift, every threshold
     * below is measuring something other than what it claims to.
     */
    public static function labReferences(): array
    {
        return [
            'white'  => ['#FFFFFF', 100.00,   0.00,    0.00],
            'black'  => ['#000000',   0.00,   0.00,    0.00],
            'grey'   => ['#808080',  53.59,   0.00,    0.00],
            'red'    => ['#FF0000',  53.24,  80.09,   67.20],
            'green'  => ['#00FF00',  87.73, -86.18,   83.18],
            'blue'   => ['#0000FF',  32.30,  79.19, -107.86],
        ];
    }

    #[DataProvider('labReferences')]
    public function test_lab_matches_published_reference_values(
        string $hex, float $l, float $a, float $b
    ): void {
        $lab = ColorService::hexToLab($hex);

        $this->assertEqualsWithDelta($l, $lab['l'], 0.05, "$hex L*");
        $this->assertEqualsWithDelta($a, $lab['a'], 0.05, "$hex a*");
        $this->assertEqualsWithDelta($b, $lab['b'], 0.05, "$hex b*");
    }

    public static function roundTripColors(): array
    {
        return array_map(fn ($h) => [$h], [
            '#FFFFFF', '#000000', '#FF0000', '#2563EB', '#C8D5C0', '#7A3B2E',
        ]);
    }

    #[DataProvider('roundTripColors')]
    public function test_hex_survives_a_lab_round_trip(string $hex): void
    {
        $this->assertSame($hex, ColorService::labToHex(ColorService::hexToLab($hex)));
    }

    // -------------------------------------------------------
    // Input handling
    // -------------------------------------------------------

    public static function hexInputs(): array
    {
        return [
            'shorthand'         => ['#abc',        '#AABBCC'],
            'shorthand no hash' => ['abc',         '#AABBCC'],
            'full'              => ['#AABBCC',     '#AABBCC'],
            'full no hash'      => ['aabbcc',      '#AABBCC'],
            'padded mixed case' => ['  #AaBbCc  ', '#AABBCC'],
            'not hex'           => ['#gggggg',     null],
            'wrong length'      => ['#12345',      null],
            'empty'             => ['',            null],
        ];
    }

    #[DataProvider('hexInputs')]
    public function test_hex_is_normalized(string $input, ?string $expected): void
    {
        $this->assertSame($expected, ColorService::normalizeHex($input));
    }

    public function test_unparseable_input_is_rejected_rather_than_guessed(): void
    {
        $this->assertNull(ColorService::hexToLab('nonsense'));
        $this->assertNull(ColorService::baseCodeFor('nonsense'));
        $this->assertNull(ColorService::describe('nonsense'));
        $this->assertFalse(ColorService::isInGamut('nonsense'));
    }

    // -------------------------------------------------------
    // Base selection
    // -------------------------------------------------------

    public static function baseCases(): array
    {
        return [
            // Light and muted -> pastel base
            'pure white'       => ['#FFFFFF', 'P'],
            'off-white cream'  => ['#F5F0E6', 'P'],
            'pale sage'        => ['#C8D5C0', 'P'],
            // Mid tones -> medium base
            'mid grey'         => ['#808080', 'M'],
            'muted slate'      => ['#9AA5B1', 'M'],
            'muted tan'        => ['#C08A5E', 'M'],
            // Dark OR saturated -> deep base
            'deep burgundy'    => ['#7A1F2B', 'D'],
            'near black'       => ['#1A1A1A', 'D'],
            'saturated red'    => ['#FF0000', 'D'],
            'saturated blue'   => ['#2563EB', 'D'],
        ];
    }

    #[DataProvider('baseCases')]
    public function test_base_is_selected_for_colorant_load(string $hex, string $expected): void
    {
        $this->assertSame($expected, ColorService::baseCodeFor($hex), $hex);
    }

    // -------------------------------------------------------
    // Gamut
    // -------------------------------------------------------

    public static function gamutCases(): array
    {
        return [
            // Real paint colours — the ceiling must not sweep these up.
            'fire-engine red'   => ['#FF0000', true],
            'strong blue'       => ['#2563EB', true],
            'deep burgundy'     => ['#7A1F2B', true],
            'cream'             => ['#F5F0E6', true],
            'pale sage'         => ['#C8D5C0', true],
            'buttercup yellow'  => ['#F7D774', true],
            'near black'        => ['#1A1A1A', true],
            // The shop's best seller. The falloff curve reaches zero at
            // L* 100, so without the min_chroma floor white's own rounding
            // error refuses it.
            'pure white'        => ['#FFFFFF', true],
            // Screen colours latex cannot reach.
            'fluorescent green' => ['#39FF14', false],
            'electric cyan'     => ['#00FFFF', false],
            'pure yellow'       => ['#FFFF00', false],
            'pure green'        => ['#00FF00', false],
            'pure blue'         => ['#0000FF', false],
            'electric magenta'  => ['#FF00FF', false],
        ];
    }

    #[DataProvider('gamutCases')]
    public function test_gamut_admits_paint_colors_and_refuses_screen_colors(
        string $hex, bool $mixable
    ): void {
        $this->assertSame($mixable, ColorService::isInGamut($hex), $hex);
    }

    /**
     * The whole point of the lightness-dependent ceiling: a flat cap refuses
     * a mixable fire-engine red (C* 105 at L* 53) while admitting an
     * unmixable electric cyan (C* 50 at L* 91). Chroma alone cannot separate
     * them — only chroma judged against lightness can.
     */
    public function test_ceiling_falls_as_lightness_rises(): void
    {
        $mid   = ColorService::maxChromaAt(50.0);
        $light = ColorService::maxChromaAt(90.0);

        $this->assertGreaterThan($light, $mid);

        $red  = ColorService::hexToLab('#FF0000');
        $cyan = ColorService::hexToLab('#00FFFF');

        $this->assertGreaterThan(
            ColorService::chroma($cyan),
            ColorService::chroma($red),
            'red is the more saturated of the two ...'
        );
        $this->assertTrue(ColorService::isInGamut('#FF0000'), '... yet red is mixable ...');
        $this->assertFalse(ColorService::isInGamut('#00FFFF'), '... and cyan is not.');
    }

    public static function outOfGamutColors(): array
    {
        return array_map(fn ($h) => [$h], ['#39FF14', '#00FFFF', '#FFFF00', '#FF00FF', '#00FF00']);
    }

    /**
     * An out-of-gamut pick must land somewhere the shop can actually mix, so
     * the picker can offer an alternative instead of a dead end.
     */
    #[DataProvider('outOfGamutColors')]
    public function test_clamping_lands_inside_the_gamut(string $hex): void
    {
        $near = ColorService::clampToGamut($hex);

        $this->assertNotNull($near);
        $this->assertTrue(ColorService::isInGamut($near), "$hex clamped to $near");
    }

    public function test_clamping_holds_lightness_so_the_colour_reads_the_same(): void
    {
        $before = ColorService::hexToLab('#39FF14');
        $after  = ColorService::hexToLab(ColorService::clampToGamut('#39FF14'));

        $this->assertEqualsWithDelta($before['l'], $after['l'], 1.0);
        $this->assertLessThan(ColorService::chroma($before), ColorService::chroma($after));
    }

    public function test_an_already_mixable_colour_is_returned_untouched(): void
    {
        $this->assertSame('#7A1F2B', ColorService::clampToGamut('#7a1f2b'));
    }

    // -------------------------------------------------------
    // describe() — the shape the API and picker consume
    // -------------------------------------------------------

    public function test_describe_reports_a_mixable_colour(): void
    {
        $d = ColorService::describe('#7a1f2b');

        $this->assertSame('#7A1F2B', $d['hex']);
        $this->assertSame('D', $d['base_code']);
        $this->assertTrue($d['in_gamut']);
        $this->assertNull($d['nearest_mixable'], 'nothing to suggest when it is already mixable');
    }

    public function test_describe_offers_an_alternative_for_an_unmixable_colour(): void
    {
        $d = ColorService::describe('#39FF14');

        $this->assertFalse($d['in_gamut']);
        $this->assertNotNull($d['nearest_mixable']);
        $this->assertTrue(ColorService::isInGamut($d['nearest_mixable']));
    }
}
