<?php

namespace Tests\Support;

use App\Services\ColorService;
use App\Services\MixCandidates;
use App\Services\MixIngredient;

/**
 * Synthetic shelves for the solver harness.
 *
 * Built in memory rather than through factories and a database: the regret
 * harness runs hundreds of thousands of mixes and has no interest in Eloquent.
 * Eligibility is already covered against real models elsewhere.
 *
 * Seeded, so a failure is reproducible. A harness that generated a fresh
 * catalogue every run would report a different worst case each time and never
 * let anyone pin one.
 *
 * WHAT IT DELIBERATELY VARIES, and why each matters:
 *
 *  - Base SIZES (1L / 2.5L / 4L). A pint is 12% of a 4L can and 47% of a 1L
 *    one, so small bases travel roughly four times further. A 4L-only fixture
 *    would hide the single weakness most likely to cost the base prune.
 *  - Low STOCK, down to one can. Exercises the per-variant demand cap, which
 *    is the difference between a recipe and one the cart will refuse.
 *  - tint_strength either side of 1.00, since calibration is expected.
 *  - Saturated tints. Pale pints barely move a 4L can, so a catalogue of them
 *    would make every prune look harmless.
 */
class CatalogueFactory
{
    /** Canonically awkward targets, kept alongside the random ones. */
    public const EXTREME_TARGETS = [
        '#000000',  // nothing on a shelf reaches it — bands must stay honest
        '#FFFFFF',  // the shop's best seller; must not be refused
        '#FF0000',  // saturated, reachable only by starting from something red
        '#00FFFF',  // saturated AND light: unreachable in latex
        '#808080',  // dead neutral, where CIE76 and ΔE2000 disagree most
    ];

    public static function make(int $seed, int $baseCount = 16, int $tintCount = 10): MixCandidates
    {
        mt_srand($seed);

        $ingredients = [];
        $id = 1;

        $sizes = [['4L', 4.0], ['1L', 1.0], ['2.5L', 2.5]];

        for ($i = 0; $i < $baseCount; $i++) {
            [, $liters] = $sizes[$i % count($sizes)];

            $ingredients[] = self::ingredient(
                id: $id++,
                hex: self::baseHex(),
                liters: $liters,
                isPint: false,
                stock: mt_rand(1, 8),
                price: mt_rand(300, 1600),
                strength: 1.0,
            );
        }

        for ($i = 0; $i < $tintCount; $i++) {
            $ingredients[] = self::ingredient(
                id: $id++,
                hex: self::tintHex(),
                liters: (float) config('paint.mix.pint_liters'),
                isPint: true,
                stock: mt_rand(1, 6),
                price: mt_rand(90, 260),
                strength: mt_rand(70, 130) / 100,
            );
        }

        return MixCandidates::fromIngredients($ingredients);
    }

    /** @return string[] */
    public static function targets(int $seed, int $count): array
    {
        mt_srand($seed);

        $targets = self::EXTREME_TARGETS;

        while (count($targets) < $count) {
            // Wall-ish: mid-to-light, never fluorescent. This is the region
            // wallSuggestions.js actually produces.
            $targets[] = sprintf(
                '#%02X%02X%02X',
                mt_rand(70, 245),
                mt_rand(70, 245),
                mt_rand(70, 245),
            );
        }

        return array_slice($targets, 0, $count);
    }

    public static function ingredient(
        int $id,
        string $hex,
        float $liters,
        bool $isPint,
        int $stock = 10,
        float $price = 500.0,
        float $strength = 1.0,
    ): MixIngredient {
        return new MixIngredient(
            variantId: $id,
            hex: $hex,
            liters: $liters,
            strength: $strength,
            stock: $stock,
            price: $price,
            isPint: $isPint,
            lab: ColorService::hexToLab($hex),
            label: "variant {$id}",
        );
    }

    /** Bases skew light — that is what a paint shelf looks like. */
    private static function baseHex(): string
    {
        return sprintf(
            '#%02X%02X%02X',
            mt_rand(110, 255),
            mt_rand(110, 255),
            mt_rand(110, 255),
        );
    }

    /** Tints skew saturated: one channel high, the others low, in a random
     *  arrangement so every hue family appears. */
    private static function tintHex(): string
    {
        $channels = [mt_rand(150, 255), mt_rand(0, 90), mt_rand(0, 140)];
        shuffle($channels);

        return sprintf('#%02X%02X%02X', ...$channels);
    }
}
