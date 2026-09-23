<?php

namespace Tests\Unit;

use App\Services\ColorService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CIEDE2000, pinned against the published reference data.
 *
 * This number is not an internal detail: it ranks every recipe the solver
 * considers, it decides which band the customer is shown ("we can mix this"
 * vs "the closest we can pour"), and it is printed beside a swatch. A subtly
 * wrong ΔE does not crash — it silently recommends the wrong can.
 *
 * The 34 pairs below are the standard test set from Sharma, Wu & Dalal (2005),
 * "The CIEDE2000 Color-Difference Formula: Implementation Notes...". They are
 * not a random sample of colours. Pairs 1-16 sit ON the hue discontinuity and
 * on the neutral axis, which is exactly where a plausible-looking
 * implementation goes wrong: get the circular-mean branch or the zero-chroma
 * case wrong and those fail while every ordinary colour still passes.
 *
 * If one of these fails, the formula is wrong. Do not adjust the tolerance.
 */
class DeltaE2000Test extends TestCase
{
    /**
     * [L1, a1, b1, L2, a2, b2, expected ΔE00]
     */
    public static function sharmaPairs(): array
    {
        return [
            // Blues far from neutral — the hue-rotation term R_T does the work.
            '1'  => [50.0000,   2.6772,  -79.7751, 50.0000,   0.0000,  -82.7485, 2.0425],
            '2'  => [50.0000,   3.1571,  -77.2803, 50.0000,   0.0000,  -82.7485, 2.8615],
            '3'  => [50.0000,   2.8361,  -74.0200, 50.0000,   0.0000,  -82.7485, 3.4412],
            '4'  => [50.0000,  -1.3802,  -84.2814, 50.0000,   0.0000,  -82.7485, 1.0000],
            '5'  => [50.0000,  -1.1848,  -84.8006, 50.0000,   0.0000,  -82.7485, 1.0000],
            '6'  => [50.0000,  -0.9009,  -85.5211, 50.0000,   0.0000,  -82.7485, 1.0000],

            // Near-neutral. The G term (a* expansion) is what makes these right.
            '7'  => [50.0000,   0.0000,    0.0000, 50.0000,  -1.0000,    2.0000, 2.3669],
            '8'  => [50.0000,  -1.0000,    2.0000, 50.0000,   0.0000,    0.0000, 2.3669],

            // Straddling the neutral axis: tiny b* sign changes swing the hue
            // angle by ~180 deg. A naive mean hue fails 9-16 and nothing else.
            '9'  => [50.0000,   2.4900,   -0.0010, 50.0000,  -2.4900,    0.0009, 7.1792],
            '10' => [50.0000,   2.4900,   -0.0010, 50.0000,  -2.4900,    0.0010, 7.1792],
            '11' => [50.0000,   2.4900,   -0.0010, 50.0000,  -2.4900,    0.0011, 7.2195],
            '12' => [50.0000,   2.4900,   -0.0010, 50.0000,  -2.4900,    0.0012, 7.2195],
            '13' => [50.0000,  -0.0010,    2.4900, 50.0000,   0.0009,   -2.4900, 4.8045],
            '14' => [50.0000,  -0.0010,    2.4900, 50.0000,   0.0010,   -2.4900, 4.8045],
            '15' => [50.0000,  -0.0010,    2.4900, 50.0000,   0.0011,   -2.4900, 4.7461],
            '16' => [50.0000,   2.5000,    0.0000, 50.0000,   0.0000,   -2.5000, 4.3065],

            // Large differences — the S_L / S_C / S_H weightings dominate.
            '17' => [50.0000,   2.5000,    0.0000, 73.0000,  25.0000,  -18.0000, 27.1492],
            '18' => [50.0000,   2.5000,    0.0000, 61.0000,  -5.0000,   29.0000, 22.8977],
            '19' => [50.0000,   2.5000,    0.0000, 56.0000, -27.0000,   -3.0000, 31.9030],
            '20' => [50.0000,   2.5000,    0.0000, 58.0000,  24.0000,   15.0000, 19.4535],

            // Constructed to land on exactly 1.0 — a scaling error shows here.
            '21' => [50.0000,   2.5000,    0.0000, 50.0000,   3.1736,    0.5854, 1.0000],
            '22' => [50.0000,   2.5000,    0.0000, 50.0000,   3.2972,    0.0000, 1.0000],
            '23' => [50.0000,   2.5000,    0.0000, 50.0000,   1.8634,    0.5757, 1.0000],
            '24' => [50.0000,   2.5000,    0.0000, 50.0000,   3.2592,    0.3350, 1.0000],

            // Real surface colours, the regime this app actually works in.
            '25' => [60.2574, -34.0099,   36.2677, 60.4626, -34.1751,   39.4387, 1.2644],
            '26' => [63.0109, -31.0961,   -5.8663, 62.8187, -29.7946,   -4.0864, 1.2630],
            '27' => [61.2901,   3.7196,   -5.3901, 61.4292,   2.2480,   -4.9620, 1.8731],
            '28' => [35.0831, -44.1164,    3.7933, 35.0232, -40.0716,    1.5901, 1.8645],
            '29' => [22.7233,  20.0904,  -46.6940, 23.0331,  14.9730,  -42.5619, 2.0373],
            '30' => [36.4612,  47.8580,   18.3852, 36.2715,  50.5065,   21.2231, 1.4146],
            '31' => [90.8027,  -2.0831,    1.4410, 91.1528,  -1.6435,    0.0447, 1.4441],
            '32' => [90.9257,  -0.5406,   -0.9208, 88.6381,  -0.8985,   -0.7239, 1.5381],

            // Very dark. S_L blows up as L* leaves 50; these pin that end.
            '33' => [ 6.7747,  -0.2908,   -2.4247,  5.8714,  -0.0985,   -2.2286, 0.6377],
            '34' => [ 2.0776,   0.0795,   -1.1350,  0.9033,  -0.0636,   -0.5514, 0.9082],
        ];
    }

    #[DataProvider('sharmaPairs')]
    public function test_matches_published_reference_data(
        float $l1, float $a1, float $b1,
        float $l2, float $a2, float $b2,
        float $expected,
    ): void {
        $actual = ColorService::deltaE2000(
            ['l' => $l1, 'a' => $a1, 'b' => $b1],
            ['l' => $l2, 'a' => $a2, 'b' => $b2],
        );

        $this->assertEqualsWithDelta($expected, $actual, 0.0001);
    }

    // -------------------------------------------------------
    // Properties the solver relies on
    // -------------------------------------------------------

    /** A colour is zero distance from itself, or nothing that ranks by ΔE
     *  can ever report an exact match. */
    public function test_distance_to_self_is_zero(): void
    {
        foreach (['#FFFFFF', '#000000', '#B91C1C', '#7FA8C9', '#808080'] as $hex) {
            $this->assertSame(0.0, ColorService::distance($hex, $hex), $hex);
        }
    }

    /**
     * ΔE2000 is symmetric. Worth pinning because the formula is NOT obviously
     * so — the mean-hue branches read asymmetrically — and the solver compares
     * candidates in whatever order the query returned them.
     */
    #[DataProvider('sharmaPairs')]
    public function test_is_symmetric(
        float $l1, float $a1, float $b1,
        float $l2, float $a2, float $b2,
        float $expected,   // unused: this test asserts a relation, not a value
    ): void {
        $forward = ColorService::deltaE2000(
            ['l' => $l1, 'a' => $a1, 'b' => $b1],
            ['l' => $l2, 'a' => $a2, 'b' => $b2],
        );
        $backward = ColorService::deltaE2000(
            ['l' => $l2, 'a' => $a2, 'b' => $b2],
            ['l' => $l1, 'a' => $a1, 'b' => $b1],
        );

        $this->assertEqualsWithDelta($forward, $backward, 1e-10);
    }

    /**
     * The disagreement with CIE76 is the reason this exists, so it is asserted
     * rather than assumed. Two near-neutrals on opposite sides of the grey
     * axis: Euclidean Lab calls them ~5 apart, ΔE2000 ~7.2 — and the ordering
     * of near-identical greys is exactly what the room palette feeds in.
     */
    public function test_disagrees_with_cie76_near_the_neutral_axis(): void
    {
        $a = ['l' => 50.0, 'a' =>  2.49, 'b' => -0.001];
        $b = ['l' => 50.0, 'a' => -2.49, 'b' =>  0.0009];

        $cie76 = sqrt(
            ($a['l'] - $b['l']) ** 2
            + ($a['a'] - $b['a']) ** 2
            + ($a['b'] - $b['b']) ** 2
        );

        $this->assertEqualsWithDelta(4.98, $cie76, 0.01);
        $this->assertEqualsWithDelta(7.1792, ColorService::deltaE2000($a, $b), 0.0001);
    }

    /** Null, never 0.0, for a variant with no colour on file — otherwise it
     *  reads as a perfect match for whatever was asked for. */
    public function test_distance_is_null_when_either_hex_is_unusable(): void
    {
        $this->assertNull(ColorService::distance(null, '#FFFFFF'));
        $this->assertNull(ColorService::distance('#FFFFFF', null));
        $this->assertNull(ColorService::distance('not a hex', '#FFFFFF'));
        $this->assertNull(ColorService::distance('', '#FFFFFF'));
    }
}
