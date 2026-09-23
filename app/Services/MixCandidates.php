<?php

namespace App\Services;

use App\Models\ProductVariant;

/**
 * Everything on the shelf that could go into a mix, read once.
 *
 * This is the ONE definition of the recipe space. Both the production solver
 * and the exhaustive reference solver in the test suite build their search
 * from this same set, which is what makes "regret is never negative" true by
 * construction rather than by hope: the pruned search explores a subset of
 * what the oracle explores, so the oracle can never come back worse. A
 * negative regret is therefore not a tuning signal — it is proof the two have
 * drifted apart about what counts as an allowed recipe.
 *
 * See REACHABILITY.md.
 */
final class MixCandidates
{
    /**
     * @param  MixIngredient[]  $bases
     * @param  MixIngredient[]  $tints
     */
    private function __construct(
        public readonly array $bases,
        public readonly array $tints,
    ) {}

    /**
     * Live stock.
     *
     * One query. `display_name` reaches for the product and its brand, so both
     * are eager-loaded — without it a 200-variant catalogue costs 400 queries
     * to build a list nobody has searched yet.
     */
    public static function fromStock(): self
    {
        $variants = ProductVariant::query()
            ->active()
            ->where('stock', '>', 0)
            ->whereNotNull('hex_code')
            ->where('hex_code', '!=', '')
            ->with('product.brand')
            ->get();

        return self::fromVariants($variants->all());
    }

    /**
     * @param  iterable<ProductVariant>  $variants
     */
    public static function fromVariants(iterable $variants): self
    {
        $ingredients = [];

        foreach ($variants as $variant) {
            if ($ingredient = MixIngredient::fromVariant($variant)) {
                $ingredients[] = $ingredient;
            }
        }

        return self::fromIngredients($ingredients);
    }

    /**
     * @param  MixIngredient[]  $ingredients
     */
    public static function fromIngredients(array $ingredients): self
    {
        $bases = [];
        $tints = [];

        foreach ($ingredients as $ingredient) {
            // Any eligible can can be the base — the design is a large can
            // with pints poured in, but nothing breaks if the base is small.
            $bases[] = $ingredient;

            // Colour is added BY THE PINT. A variant may legitimately be both;
            // MixController permits it and the stock check below accounts for
            // the one can already spent as the base.
            if ($ingredient->isPint) {
                $tints[] = $ingredient;
            }
        }

        return new self($bases, $tints);
    }

    public function isEmpty(): bool
    {
        return $this->bases === [];
    }
}
