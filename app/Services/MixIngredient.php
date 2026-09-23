<?php

namespace App\Services;

use App\Models\ProductVariant;

/**
 * One can the solver may pour, flattened out of Eloquent.
 *
 * WHY NOT PASS ProductVariant AROUND. The search evaluates thousands of
 * recipes. `$variant->liters` runs a regex over free text on every read and
 * `$variant->is_pint` runs another, so reading them once here is the
 * difference between arithmetic and a hot loop full of pattern matching. The
 * Lab value is cached for the same reason — ranking is ΔE2000, and converting
 * the hex costs more than the distance itself.
 *
 * Eligibility lives in fromVariant() and MIRRORS MixController's own
 * rejectBase() / rejectTint(). If the two ever disagree the solver will
 * propose a recipe the cart refuses, which is why a test asserts every
 * returned recipe survives the endpoint's rules.
 */
final class MixIngredient
{
    /**
     * @param  array{l:float,a:float,b:float}  $lab
     */
    public function __construct(
        public readonly int $variantId,
        public readonly string $hex,
        public readonly float $liters,
        public readonly float $strength,
        public readonly int $stock,
        public readonly float $price,
        public readonly bool $isPint,
        public readonly array $lab,
        public readonly string $label,
    ) {}

    /**
     * Null when this can cannot be an ingredient at all.
     *
     * Every rejection here has a counterpart in MixController: archived (the
     * product too), no colour on file, an unparseable size, or nothing on the
     * shelf. Out of stock is the solver's own addition — the endpoint checks
     * stock at add-to-cart, but proposing a can the shop does not have is a
     * recommendation that fails after the customer has approved it.
     */
    public static function fromVariant(ProductVariant $variant): ?self
    {
        $hex = ColorService::normalizeHex($variant->hex_code);
        $liters = $variant->liters;

        if ($hex === null || $liters === null || $liters <= 0) {
            return null;
        }

        if ($variant->is_archived || $variant->product?->is_archived) {
            return null;
        }

        if ((int) $variant->stock <= 0) {
            return null;
        }

        // A variant with no calibration mixes at full strength, same as the
        // column default. Anything at or below zero would contribute no colour
        // while still being paid for and poured — see config('paint.mix').
        $strength = (float) ($variant->tint_strength ?? 1.0);

        return new self(
            variantId: (int) $variant->id,
            hex: $hex,
            liters: (float) $liters,
            strength: $strength > 0 ? $strength : 1.0,
            stock: (int) $variant->stock,
            price: (float) $variant->price,
            isPint: (bool) $variant->is_pint,
            lab: ColorService::hexToLab($hex),
            label: $variant->display_name,
        );
    }

    /** What ColorService::mix() wants, for `$cans` cans of this ingredient.
     *  `$asBase` because the base ALWAYS mixes at strength 1.00 whatever its
     *  own row says — calibration describes how hard an ADDITION pulls, and
     *  applying the base's factor would shift a recipe nothing was added to. */
    public function component(int $cans, bool $asBase = false): array
    {
        return [
            'hex' => $this->hex,
            'liters' => $this->liters * $cans,
            'strength' => $asBase ? 1.0 : $this->strength,
        ];
    }
}
