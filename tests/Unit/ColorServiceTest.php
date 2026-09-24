<?php

namespace Tests\Unit;

use App\Services\ColorService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ColorService's colour conversions, pinned against published values. The
 * distance (DeltaE2000Test) and the mix (PaintMixTest) have their own files.
 *
 * Extends Tests\TestCase (not PHPUnit's) because the mix constants live in
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
        $this->assertNull(ColorService::distance('nonsense', '#FFFFFF'));
        $this->assertNull(ColorService::mix([['hex' => 'nonsense', 'liters' => 4]]));
    }
}
