<?php

namespace App\Services;

/**
 * Colour maths for custom tinting.
 *
 * Answers two questions about a hex the customer picked on their phone:
 *
 *   1. Which base must it be tinted into?   baseCodeFor()
 *   2. Can it be made in latex paint at all? isInGamut()
 *
 * Both are decided in CIELAB, NOT in HSL/HSV. HSL "lightness" is a channel
 * average, not perceived lightness: a saturated yellow and a saturated blue
 * sit at the same HSL L but roughly 50 points of L* apart, and would pick
 * different bases while the rule says they should not. CIELAB is
 * approximately perceptually uniform, which is what the thresholds mean.
 *
 * Thresholds live in config/paint.php — they model pigment loading, not the
 * dispenser's spec. See CUSTOM_COLOR.md.
 */
class ColorService
{
    /** CIE standard illuminant D65, the sRGB white point. */
    private const WHITE_X = 0.95047;

    private const WHITE_Y = 1.00000;

    private const WHITE_Z = 1.08883;

    /** (6/29)^3 — the knee where the CIELAB transfer function turns linear. */
    private const EPSILON = 0.008856451679035631;

    private const KAPPA = 7.787037037037035;   // (1/3)(29/6)^2

    /** 25^7, the pivot in CIEDE2000's chroma weighting. Precomputed: it is
     *  evaluated twice per distance and the solver calls that ~5,000 times. */
    private const POW25_7 = 6103515625.0;

    // -------------------------------------------------------
    // Input handling
    // -------------------------------------------------------

    /**
     * Accepts "#aabbcc", "aabbcc", "#abc", "abc". Returns "#AABBCC" or null.
     * Everything else in this class assumes it has been through here.
     */
    public static function normalizeHex(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }

        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return '#'.strtoupper($hex);
    }

    /** @return array{0:int,1:int,2:int}|null 0-255 per channel */
    public static function hexToRgb(?string $hex): ?array
    {
        if (! $hex = self::normalizeHex($hex)) {
            return null;
        }

        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }

    // -------------------------------------------------------
    // sRGB <-> CIELAB
    // -------------------------------------------------------

    /** @return array{l:float,a:float,b:float}|null */
    public static function hexToLab(?string $hex): ?array
    {
        if (! $rgb = self::hexToRgb($hex)) {
            return null;
        }

        // sRGB is gamma-encoded; linearise before any colour maths.
        [$r, $g, $b] = array_map(
            fn ($c) => self::srgbToLinear($c / 255),
            $rgb
        );

        // Linear sRGB -> CIE XYZ (D65)
        $x = 0.4124564 * $r + 0.3575761 * $g + 0.1804375 * $b;
        $y = 0.2126729 * $r + 0.7151522 * $g + 0.0721750 * $b;
        $z = 0.0193339 * $r + 0.1191920 * $g + 0.9503041 * $b;

        $fx = self::labF($x / self::WHITE_X);
        $fy = self::labF($y / self::WHITE_Y);
        $fz = self::labF($z / self::WHITE_Z);

        return [
            'l' => 116 * $fy - 16,
            'a' => 500 * ($fx - $fy),
            'b' => 200 * ($fy - $fz),
        ];
    }

    /** @param array{l:float,a:float,b:float} $lab */
    public static function labToHex(array $lab): string
    {
        $fy = ($lab['l'] + 16) / 116;
        $fx = $fy + $lab['a'] / 500;
        $fz = $fy - $lab['b'] / 200;

        $x = self::WHITE_X * self::labFInverse($fx);
        $y = self::WHITE_Y * self::labFInverse($fy);
        $z = self::WHITE_Z * self::labFInverse($fz);

        // CIE XYZ (D65) -> linear sRGB
        $r = 3.2404542 * $x - 1.5371385 * $y - 0.4985314 * $z;
        $g = -0.9692660 * $x + 1.8760108 * $y + 0.0415560 * $z;
        $b = 0.0556434 * $x - 0.2040259 * $y + 1.0572252 * $z;

        $channels = array_map(function ($c) {
            $c = self::linearToSrgb($c);

            return str_pad(
                strtoupper(dechex((int) round(max(0, min(1, $c)) * 255))),
                2,
                '0',
                STR_PAD_LEFT
            );
        }, [$r, $g, $b]);

        return '#'.implode('', $channels);
    }

    /** Chroma — distance from the neutral axis. How saturated the colour is. */
    public static function chroma(array $lab): float
    {
        return sqrt($lab['a'] ** 2 + $lab['b'] ** 2);
    }

    // -------------------------------------------------------
    // Perceptual distance
    // -------------------------------------------------------

    /**
     * CIEDE2000 — how different two colours look, as one number.
     *
     * WHY NOT CIE76. The mobile `lib/color.js` computes plain Euclidean
     * distance in Lab and its comment says "plenty for clustering / round-trip
     * checks; ΔE2000 is not needed". That is correct for what it describes and
     * stops being correct the moment the number is shown to a customer as a
     * quality claim: CIE76 assumes Lab is perceptually uniform, and it is not.
     * It overstates differences in saturated blues and understates them near
     * the neutral axis, where two greys on opposite sides of neutral read as
     * far apart to the formula and identical to a person. CIEDE2000 adds the
     * three weighting functions (S_L, S_C, S_H), the a* expansion near
     * neutral, and the blue-region hue rotation that correct exactly those.
     *
     * So: ΔE2000 for anything the customer reads, CIE76 stays in
     * roomPalette.js clustering where it runs over thousands of samples and
     * the existing argument for it holds. See REACHABILITY.md.
     *
     * kL = kC = kH = 1 (reference conditions), so they are omitted rather than
     * carried as parameters nothing in this app would ever set.
     *
     * Pinned against the published Sharma/Wu/Dalal test set in
     * tests/Unit/DeltaE2000Test.php — 34 pairs chosen to break naive
     * implementations at the hue discontinuity. Do not "simplify" the branches
     * below; that is what those pairs exist to catch.
     *
     * @param  array{l:float,a:float,b:float}  $lab1
     * @param  array{l:float,a:float,b:float}  $lab2
     */
    public static function deltaE2000(array $lab1, array $lab2): float
    {
        $l1 = (float) $lab1['l'];
        $a1 = (float) $lab1['a'];
        $b1 = (float) $lab1['b'];
        $l2 = (float) $lab2['l'];
        $a2 = (float) $lab2['a'];
        $b2 = (float) $lab2['b'];

        $c1 = sqrt($a1 ** 2 + $b1 ** 2);
        $c2 = sqrt($a2 ** 2 + $b2 ** 2);
        $cBar = ($c1 + $c2) / 2;

        // G expands a* for low-chroma colours. This is the term that fixes
        // CIE76's worst failure — two near-neutrals straddling the grey axis.
        $cBar7 = $cBar ** 7;
        $g = 0.5 * (1 - sqrt($cBar7 / ($cBar7 + self::POW25_7)));

        $a1p = (1 + $g) * $a1;
        $a2p = (1 + $g) * $a2;

        $c1p = sqrt($a1p ** 2 + $b1 ** 2);
        $c2p = sqrt($a2p ** 2 + $b2 ** 2);

        $h1p = self::hueAngle($a1p, $b1);
        $h2p = self::hueAngle($a2p, $b2);

        $dLp = $l2 - $l1;
        $dCp = $c2p - $c1p;

        $cProduct = $c1p * $c2p;

        // Hue difference, and the whole difficulty of this formula. An angle
        // is circular, so 359 deg and 1 deg are 2 deg apart, not 358 — and a
        // colour ON the neutral axis has no hue to difference at all.
        if ($cProduct == 0.0) {
            $dhp = 0.0;
        } else {
            $dhp = $h2p - $h1p;

            if ($dhp > 180) {
                $dhp -= 360;
            } elseif ($dhp < -180) {
                $dhp += 360;
            }
        }

        $dHp = 2 * sqrt($cProduct) * sin(deg2rad($dhp / 2));

        $lBarP = ($l1 + $l2) / 2;
        $cBarP = ($c1p + $c2p) / 2;

        // Mean hue. Same circularity, and the zero-chroma case must NOT be
        // averaged: one of the two angles is meaningless, so summing keeps
        // whichever one is real.
        if ($cProduct == 0.0) {
            $hBarP = $h1p + $h2p;
        } elseif (abs($h1p - $h2p) <= 180) {
            $hBarP = ($h1p + $h2p) / 2;
        } elseif ($h1p + $h2p < 360) {
            $hBarP = ($h1p + $h2p + 360) / 2;
        } else {
            $hBarP = ($h1p + $h2p - 360) / 2;
        }

        $t = 1
            - 0.17 * cos(deg2rad($hBarP - 30))
            + 0.24 * cos(deg2rad(2 * $hBarP))
            + 0.32 * cos(deg2rad(3 * $hBarP + 6))
            - 0.20 * cos(deg2rad(4 * $hBarP - 63));

        $sL = 1 + (0.015 * ($lBarP - 50) ** 2) / sqrt(20 + ($lBarP - 50) ** 2);
        $sC = 1 + 0.045 * $cBarP;
        $sH = 1 + 0.015 * $cBarP * $t;

        // The hue-rotation term. It only bites around 275 deg — the blues,
        // which is precisely where this app's wall suggestions live.
        $cBarP7 = $cBarP ** 7;
        $rC = 2 * sqrt($cBarP7 / ($cBarP7 + self::POW25_7));
        $dTheta = 30 * exp(-((($hBarP - 275) / 25) ** 2));
        $rT = -sin(deg2rad(2 * $dTheta)) * $rC;

        $termL = $dLp / $sL;
        $termC = $dCp / $sC;
        $termH = $dHp / $sH;

        return sqrt(
            $termL ** 2
            + $termC ** 2
            + $termH ** 2
            + $rT * $termC * $termH
        );
    }

    /**
     * Perceptual distance between two hexes, or null if either is unparseable.
     *
     * Null rather than 0.0 on bad input: zero is a real answer meaning
     * "identical", and a variant with no hex_code on file must never read as a
     * perfect match for whatever the customer asked for.
     */
    public static function distance(?string $hexA, ?string $hexB): ?float
    {
        $labA = self::hexToLab($hexA);
        $labB = self::hexToLab($hexB);

        if ($labA === null || $labB === null) {
            return null;
        }

        return self::deltaE2000($labA, $labB);
    }

    /** Hue angle in degrees, 0–360. Undefined on the neutral axis, where the
     *  convention is 0 and the callers above never use it. */
    private static function hueAngle(float $a, float $b): float
    {
        if ($a == 0.0 && $b == 0.0) {
            return 0.0;
        }

        $degrees = rad2deg(atan2($b, $a));

        return $degrees >= 0 ? $degrees : $degrees + 360;
    }

    // -------------------------------------------------------
    // The two questions this class exists to answer
    // -------------------------------------------------------

    /**
     * Which base this colour must be tinted into: 'P', 'M' or 'D'.
     * Null for an unparseable hex.
     *
     * A pale sage and a deep burgundy cannot go into the same can — a light
     * colour needs a base with plenty of white, a saturated dark one needs a
     * base with almost none or the colorant cannot reach the target and the
     * result is washed out. Because that depends only on colorant load, it is
     * computable from the colour and needs no colour chart.
     */
    public static function baseCodeFor(?string $hex): ?string
    {
        if (! $lab = self::hexToLab($hex)) {
            return null;
        }

        $cfg = config('paint.base');
        $l = $lab['l'];
        $c = self::chroma($lab);

        // Light AND muted -> pastel base.
        if ($l > $cfg['pastel']['min_l'] && $c < $cfg['pastel']['max_c']) {
            return $cfg['pastel']['code'];
        }

        // Dark OR saturated -> deep base.
        if ($l < $cfg['deep']['max_l'] || $c > $cfg['deep']['min_c']) {
            return $cfg['deep']['code'];
        }

        return $cfg['medium']['code'];
    }

    /**
     * The most saturated a paint can be at a given lightness.
     *
     * Not a constant: to make a colour light you add white, and white
     * desaturates, so the achievable chroma falls away as L* climbs. A flat
     * cap has this backwards — it refuses a fire-engine red (C* 105 at
     * L* 53, mixable) while passing an electric cyan at L* 91.
     */
    public static function maxChromaAt(float $lightness): float
    {
        $cfg = config('paint.gamut');

        if ($lightness <= $cfg['knee_l']) {
            return (float) $cfg['max_chroma'];
        }

        $headroom = max(0.0, (100 - $lightness) / (100 - $cfg['knee_l']));

        // The floor matters: the curve reaches 0 at L* 100, where pure white
        // lives, and white's chroma is not exactly 0 once it has been through
        // a float conversion. Without this, the shop's best-selling colour is
        // refused by rounding error.
        return max(
            (float) $cfg['min_chroma'],
            $cfg['max_chroma'] * ($headroom ** $cfg['falloff'])
        );
    }

    /**
     * Whether latex paint can reach this colour at all.
     * Fluorescents and electric brights sit outside the pigment gamut;
     * metallics and pearls are a different product entirely.
     *
     * Weakest around cyan and blue-green, where CIELAB is known to
     * understate chroma — see Known limitations in CUSTOM_COLOR.md.
     */
    public static function isInGamut(?string $hex): bool
    {
        if (! $lab = self::hexToLab($hex)) {
            return false;
        }

        return self::chroma($lab) <= self::maxChromaAt($lab['l']) + 1e-9;
    }

    /**
     * The nearest mixable colour to an out-of-gamut one — same hue and
     * lightness, chroma pulled back to the limit. Lets the picker offer
     * "we can't mix that, but we can mix this" instead of a dead end.
     */
    public static function clampToGamut(?string $hex): ?string
    {
        if (! $lab = self::hexToLab($hex)) {
            return null;
        }

        if (self::isInGamut($hex)) {
            return self::normalizeHex($hex);
        }

        // Lightness is held fixed while chroma is pulled in, so the ceiling
        // is fixed too — hue and how light the colour reads both survive.
        $target = self::maxChromaAt($lab['l']) * 0.98;

        // The sRGB round-trip clips, which can nudge chroma back up, so
        // verify and step down instead of trusting a single conversion.
        for ($i = 0; $i < 12; $i++) {
            $c = self::chroma($lab);

            if ($c <= 0.0001) {
                break;
            }

            $scale = min(1.0, $target / $c);
            $lab['a'] *= $scale;
            $lab['b'] *= $scale;

            $candidate = self::labToHex($lab);

            if (self::isInGamut($candidate)) {
                return $candidate;
            }

            $target *= 0.9;
        }

        return self::labToHex($lab);
    }

    /**
     * Everything the API and the picker need about a colour, in one call.
     *
     * @return array{hex:string,lightness:float,chroma:float,base_code:?string,in_gamut:bool,nearest_mixable:?string}|null
     */
    public static function describe(?string $hex): ?array
    {
        if (! $normalized = self::normalizeHex($hex)) {
            return null;
        }

        $lab = self::hexToLab($normalized);
        $inGamut = self::isInGamut($normalized);

        return [
            'hex' => $normalized,
            'lightness' => round($lab['l'], 2),
            'chroma' => round(self::chroma($lab), 2),
            'base_code' => self::baseCodeFor($normalized),
            'in_gamut' => $inGamut,
            'nearest_mixable' => $inGamut ? null : self::clampToGamut($normalized),
        ];
    }

    // -------------------------------------------------------
    // Mixing — predicting the colour of a customer's recipe
    // -------------------------------------------------------

    /**
     * The colour you get by pouring these paints together.
     *
     * Single-constant Kubelka-Munk per RGB channel. Subtractive, because
     * paint is: a naive channel average says a black pint in 4L of white
     * stays #E7E7E7, which is not a mix, it is arithmetic. This says #696969.
     *
     * Runs on gamma-encoded sRGB rather than linearised reflectance — see the
     * note in config/paint.php for the measurement behind that choice.
     *
     * OVER-PREDICTS DARK ADDITIONS. The single-constant model has one optical
     * constant where paint has two, so it cannot know that titanium white
     * scatters far more than a colourant absorbs. Correct it per ingredient
     * with `strength`, calibrated against real mixes at the counter.
     *
     * @param  array<int,array{hex:string,liters:float,strength?:float}>  $components
     *                                                                                 `liters` is the component's TOTAL contribution (cans x can size).
     * @return string|null null when nothing usable was passed
     */
    public static function mix(array $components): ?string
    {
        $usable = [];

        foreach ($components as $c) {
            $hex = self::normalizeHex($c['hex'] ?? null);
            $liters = (float) ($c['liters'] ?? 0);

            // A component with no colour on file or no volume is not a
            // component. Skipped rather than fatal: one variant missing a
            // hex_code should not lose the customer their whole recipe.
            if ($hex === null || $liters <= 0) {
                continue;
            }

            $usable[] = [
                'rgb' => self::hexToRgb($hex),
                'hex' => $hex,
                'liters' => $liters,
                'strength' => max(0.0, (float) ($c['strength'] ?? 1.0)),
            ];
        }

        if ($usable === []) {
            return null;
        }

        // One component is not a mix. Short-circuited so a lone paint returns
        // ITSELF exactly — the clamp below would otherwise shift it by a
        // rounding step, and a base with no tints yet must show its own colour.
        if (count($usable) === 1) {
            return $usable[0]['hex'];
        }

        $total = array_sum(array_column($usable, 'liters'));
        $cfg = config('paint.mix');
        $floor = (float) $cfg['reflectance_floor'];
        $ceil = (float) $cfg['reflectance_ceil'];

        $channels = [];

        for ($ch = 0; $ch < 3; $ch++) {
            $ks = 0.0;

            foreach ($usable as $c) {
                $r = min($ceil, max($floor, $c['rgb'][$ch] / 255));

                // Kubelka-Munk: absorption over scattering for an opaque film.
                $ks += ($c['liters'] / $total)
                    * ((1 - $r) ** 2 / (2 * $r))
                    * $c['strength'];
            }

            // Invert back to reflectance.
            $r = 1 + $ks - sqrt($ks ** 2 + 2 * $ks);

            $channels[] = str_pad(
                strtoupper(dechex((int) round(max(0.0, min(1.0, $r)) * 255))),
                2,
                '0',
                STR_PAD_LEFT
            );
        }

        return '#'.implode('', $channels);
    }

    // -------------------------------------------------------
    // Transfer functions
    // -------------------------------------------------------

    private static function srgbToLinear(float $c): float
    {
        return $c <= 0.04045
            ? $c / 12.92
            : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function linearToSrgb(float $c): float
    {
        return $c <= 0.0031308
            ? 12.92 * $c
            : 1.055 * (max(0.0, $c) ** (1 / 2.4)) - 0.055;
    }

    private static function labF(float $t): float
    {
        return $t > self::EPSILON
            ? $t ** (1 / 3)
            : self::KAPPA * $t + 16 / 116;
    }

    private static function labFInverse(float $t): float
    {
        $cubed = $t ** 3;

        return $cubed > self::EPSILON
            ? $cubed
            : ($t - 16 / 116) / self::KAPPA;
    }
}
