<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Paints to mix with — a base to start from and pint cans to add.
 *
 * Exists because the mixing feature is INERT without pint variants, and the
 * catalogue has none: every size in the system is 1L and up. Until someone
 * stocks pints in the admin the ingredient picker is simply empty, so this
 * seeds enough to exercise and demonstrate the flow.
 *
 * The colourant pints are STRONG on purpose. A pint of finished paint is
 * mostly white base already, so a pale shade barely moves a 4L can — sixteen
 * pints of #CC2222 into 4L of white still only reaches #D52E2E. Seeding pale
 * pints would "work" while demonstrating nothing, so these are the deepest
 * useful ends of each hue.
 *
 * tint_strength is left at 1.00 across the board. Single-constant Kubelka-Munk
 * over-states dark additions, so real calibration means comparing a prediction
 * with a real mix at the counter and dialling each colourant back — which is
 * a shop activity, not something a seeder can invent.
 *
 *   php artisan db:seed --class=MixDemoSeeder
 *
 * Idempotent — safe to re-run.
 *
 * See MIXING.md.
 */
class MixDemoSeeder extends Seeder
{
    public function run(): void
    {
        $brand = Brand::firstOrCreate(
            ['brand_name' => 'Boysen'],
            ['is_archived' => false]
        );

        $category = Category::firstOrCreate(
            ['category_name' => 'Latex Paint'],
            ['is_archived' => false]
        );

        // ---- Bases: what a mix starts from ----
        $bases = Product::updateOrCreate(
            ['brand_id' => $brand->id, 'name' => 'Permacoat Latex — Mixing Base'],
            ['description' => 'Start a colour of your own from one of these, then add colour by the pint.', 'is_archived' => false],
        );
        $bases->categories()->syncWithoutDetaching([$category->id]);

        $this->variants($bases, [
            // colour name,   hex,       sizes and prices
            ['White',         '#FFFFFF', ['4L' => 1150, '1L' => 340]],
            ['Warm White',    '#F5EFE3', ['4L' => 1150, '1L' => 340]],
            ['Soft Grey',     '#C9C9C4', ['4L' => 1180, '1L' => 352]],
            // A deep base is the ONLY route to a deep colour: pints lighten and
            // dull a mix, they cannot deepen one. Someone wanting a navy wall
            // starts here and lightens, rather than starting at white.
            ['Deep Charcoal', '#26323C', ['4L' => 1290, '1L' => 385]],
        ]);

        // ---- Colourants: what gets added, by the pint ----
        $tints = Product::updateOrCreate(
            ['brand_id' => $brand->id, 'name' => 'Permacoat Latex — Mixing Colours'],
            ['description' => 'Pint cans for mixing into a base. Bought with the mix and poured in store.', 'is_archived' => false],
        );
        $tints->categories()->syncWithoutDetaching([$category->id]);

        $this->variants($tints, [
            ['Deep Red',    '#B3161C', ['Pint' => 295]],
            ['Deep Blue',   '#17357A', ['Pint' => 295]],
            ['Deep Yellow', '#E8A400', ['Pint' => 285]],
            ['Deep Green',  '#1B5E34', ['Pint' => 295]],
            ['Burnt Umber', '#6B3F23', ['Pint' => 275]],
            ['Lamp Black',  '#141414', ['Pint' => 265]],
            ['Titanium White', '#FFFFFF', ['Pint' => 255]],
        ]);

        $this->command?->info('Seeded mixing bases and pint colourants.');
    }

    /** @param array<int,array{0:string,1:string,2:array<string,int>}> $rows */
    private function variants(Product $product, array $rows): void
    {
        $sort = 0;

        foreach ($rows as [$name, $hex, $sizes]) {
            foreach ($sizes as $size => $price) {
                $product->variants()->updateOrCreate(
                    [
                        // The identity unique is (product, colour, name, size,
                        // base) — matching on all of it is what makes this
                        // idempotent rather than duplicating on every run.
                        'color_code'  => '',
                        'color_name'  => $name,
                        'size_volume' => $size,
                        'base_code'   => '',
                    ],
                    [
                        'sort_order'          => $sort++,
                        'hex_code'            => $hex,
                        'price'               => $price,
                        'stock'               => 40,
                        'low_stock_threshold' => 5,
                        'tint_strength'       => 1.00,
                        'is_archived'         => false,
                    ],
                );
            }
        }
    }
}
