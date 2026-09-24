<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\TintColor;
use Illuminate\Database\Seeder;

/**
 * Bases and colorant presets for the tint-recipe bench.
 *
 * The bases are a mixing-base product (hidden from the catalogue), typed
 * white, pastel and deep, in 1L and 4L. The deep base shows colorant far more
 * strongly (its tint_response) and takes more of it: it is the route to a
 * deep colour, which white base cannot reach however much is poured in.
 *
 * The presets are the usual universal colorant set. Their hexes are the
 * colorant's masstone, which is what the Kubelka-Munk preview needs; the
 * step reflects strength (black is measured finer than yellow oxide).
 * tint_strength stays 1.00 — calibration is done against real mixes, not
 * invented by a seeder.
 *
 *   php artisan db:seed --class=TintMixDemoSeeder
 *
 * Idempotent — safe to re-run. See MIXING.md.
 */
class TintMixDemoSeeder extends Seeder
{
    public function run(): void
    {
        $brand = Brand::firstOrCreate(['brand_name' => 'Boysen'], ['is_archived' => false]);
        $category = Category::firstOrCreate(['category_name' => 'Latex Paint'], ['is_archived' => false]);

        $bases = Product::updateOrCreate(
            ['brand_id' => $brand->id, 'name' => 'Permacoat Latex — Tint Base'],
            [
                'description' => 'Untinted latex base, mixed to your colour in store.',
                'is_mixing_base' => true,
                'is_archived' => false,
            ],
        );
        $bases->categories()->syncWithoutDetaching([$category->id]);

        // By TYPE: the type sets each can's name, preview colour, tint
        // response and colorant capacity (config/paint.php "base_types").
        $sort = 0;
        foreach ([
            // type,   [size => [litres, price]]
            ['white',  ['1L' => [1, 330], '4L' => [4, 1120]]],
            ['pastel', ['1L' => [1, 340], '4L' => [4, 1150]]],
            ['deep',   ['1L' => [1, 360], '4L' => [4, 1220]]],
        ] as [$type, $sizes]) {
            $label = config("paint.mix.base_types.{$type}.label");

            foreach ($sizes as $size => [$liters, $price]) {
                $bases->variants()->updateOrCreate(
                    ['color_code' => '', 'color_name' => $label, 'size_volume' => $size],
                    [
                        'base_type' => $type,
                        'sort_order' => $sort++,
                        'volume_liters' => $liters,
                        'max_tint_ml_per_liter' => null,
                        'price' => $price,
                        'stock' => 30,
                        'low_stock_threshold' => 5,
                        'is_archived' => false,
                    ],
                );
            }
        }

        foreach ([
            // name,            hex,       step
            ['Oxide Red',       '#9B3A2A', 2],
            ['Yellow Oxide',    '#C9A227', 5],
            ['Organic Red',     '#B3161C', 1],
            ['Organic Yellow',  '#F2B705', 2],
            ['Phthalo Blue',    '#17357A', 1],
            ['Phthalo Green',   '#0B5E45', 1],
            ['Raw Umber',       '#6B4F36', 2],
            ['Carbon Black',    '#1A1A1A', 0.5],
            ['Titanium White',  '#FFFFFF', 5],
        ] as $i => [$name, $hex, $step]) {
            TintColor::updateOrCreate(
                ['name' => $name],
                ['hex_code' => $hex, 'step_ml' => $step, 'sort_order' => $i, 'is_archived' => false],
            );
        }

        $this->command?->info('Seeded tint bases and colorant presets.');
    }
}
