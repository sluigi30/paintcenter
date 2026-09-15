<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * A custom-colour product to demonstrate and test against.
 *
 * Three bases x three sizes, because that is the case the feature exists to
 * handle: the customer picks a colour, the server works out which base can
 * carry it, and only the sizes stocked in THAT base are offered. Seeding one
 * base would pass every test while proving nothing.
 *
 * Prices and tint fees are illustrative but in the right region for the
 * Philippine market. Tint rises with can size and base depth, which is what
 * makes the fee per-variant rather than a percentage.
 *
 *   php artisan db:seed --class=CustomColorDemoSeeder
 *
 * Idempotent — safe to re-run; it updates the same product rather than
 * creating a second one.
 */
class CustomColorDemoSeeder extends Seeder
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

        $product = Product::updateOrCreate(
            [
                'brand_id' => $brand->id,
                'name'     => 'Permacoat Latex — Custom Colour',
            ],
            [
                // A custom-colour product has no colour of its own; every
                // order carries the one the customer chose, so its variants
                // leave color_code / color_name empty.
                'is_custom_color' => true,
                'is_archived'     => false,
            ]
        );

        $product->categories()->syncWithoutDetaching([$category->id]);

        // [size, base, price, tint fee, stock]
        $variants = [
            ['1L',  'P',  360,  80, 40],
            ['1L',  'M',  380, 120, 30],
            ['1L',  'D',  410, 160, 25],

            ['4L',  'P', 1250, 150, 24],
            ['4L',  'M', 1320, 200, 18],
            ['4L',  'D', 1420, 250, 12],

            ['16L', 'P', 4400, 500, 8],
            ['16L', 'M', 4650, 650, 6],
            ['16L', 'D', 4980, 800, 4],
        ];

        foreach ($variants as [$size, $base, $price, $fee, $stock]) {
            $product->variants()->updateOrCreate(
                ['size_volume' => $size, 'base_code' => $base],
                [
                    'price'               => $price,
                    'tint_fee'            => $fee,
                    'stock'               => $stock,
                    'low_stock_threshold' => 5,
                    'is_archived'         => false,
                ]
            );
        }

        $this->command?->info("Seeded product #{$product->id}: {$product->name}");
        $this->command?->info('  ' . $product->variants()->count() . ' variants (3 sizes x 3 bases)');
        $this->command?->info('  Try: pale sage #C8D5C0 -> pastel, deep burgundy #7A1F2B -> deep');
    }
}
