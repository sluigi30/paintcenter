<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The one irreversible step of the tint-recipe revamp: retiring the dispenser
 * and pint designs (2026_09_24_000002). Columns go, so the data in them must be
 * FOLDED into the new model first — this rolls the migration back, lays down
 * rows shaped the old way, runs it forward, and checks nothing that mattered
 * was lost.
 */
class RetireLegacyMixingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path('migrations/2026_09_24_000002_retire_pint_and_dispenser_mixing.php');
    }

    public function test_legacy_data_is_folded_before_the_columns_go(): void
    {
        $migration = $this->migration();
        $migration->down();

        $brand = DB::table('brands')->insertGetId(['brand_name' => 'Boysen', 'created_at' => now(), 'updated_at' => now()]);
        $user = DB::table('users')->insertGetId([
            'first_name' => 'C', 'last_name' => 'X', 'email' => 'c@example.test',
            'password' => 'x', 'role' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A dispenser-era custom-colour product: three base cans told apart
        // ONLY by base_code, and one ordinary paint.
        $custom = DB::table('products')->insertGetId([
            'brand_id' => $brand, 'name' => 'Permacoat — Custom Colour', 'is_custom_color' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $plain = DB::table('products')->insertGetId([
            'brand_id' => $brand, 'name' => 'Permacoat — White', 'is_custom_color' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['P', 'M', 'D'] as $i => $code) {
            DB::table('product_variants')->insert([
                'product_id' => $custom, 'color_code' => '', 'color_name' => '', 'size_volume' => '4L',
                'base_code' => $code, 'price' => 1100, 'stock' => 5, 'tint_fee' => 80, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $whiteCan = DB::table('product_variants')->insertGetId([
            'product_id' => $plain, 'color_code' => '', 'color_name' => 'White', 'size_volume' => '4L',
            'base_code' => '', 'price' => 900, 'stock' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Carts: a pint-mix line, a dispenser custom line, and a plain can.
        $cart = fn (array $extra) => DB::table('cart_items')->insert($extra + [
            'user_id' => $user, 'product_id' => $plain, 'product_variant_id' => $whiteCan,
            'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cart(['mix_group' => 'g-1', 'mix_role' => 'base', 'custom_hex' => '#ED7878']);
        $cart(['custom_hex' => '#4F7942']);
        $cart([]);

        $migration->up();

        // The custom product IS a mixing base now, its cans named by base.
        $this->assertTrue((bool) DB::table('products')->where('id', $custom)->value('is_mixing_base'));
        $this->assertFalse((bool) DB::table('products')->where('id', $plain)->value('is_mixing_base'));
        $this->assertSame(
            ['Pastel Base', 'Medium Base', 'Deep Base'],
            DB::table('product_variants')->where('product_id', $custom)->orderBy('sort_order')->pluck('color_name')->all(),
            'P/M/D must survive as names, or the three cans collide once base_code leaves the identity key.',
        );
        $this->assertSame('White', DB::table('product_variants')->where('id', $whiteCan)->value('color_name'));

        // Only the plain can is left in the cart.
        $this->assertSame(1, DB::table('cart_items')->count());
        $this->assertNull(DB::table('cart_items')->value('custom_hex'));

        // The retired columns are gone; order history keeps its own.
        $this->assertFalse(Schema::hasColumn('products', 'is_custom_color'));
        $this->assertFalse(Schema::hasColumn('product_variants', 'base_code'));
        $this->assertFalse(Schema::hasColumn('product_variants', 'tint_fee'));
        $this->assertFalse(Schema::hasColumn('cart_items', 'mix_group'));
        $this->assertTrue(Schema::hasColumn('order_items', 'mix_group'));
        $this->assertTrue(Schema::hasColumn('order_items', 'tint_fee'));
    }

    public function test_the_identity_key_still_refuses_a_duplicate_can(): void
    {
        $brand = DB::table('brands')->insertGetId(['brand_name' => 'Boysen', 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId(['brand_id' => $brand, 'name' => 'P', 'created_at' => now(), 'updated_at' => now()]);
        $row = ['product_id' => $product, 'color_code' => '', 'color_name' => 'White', 'size_volume' => '4L', 'price' => 1, 'stock' => 1];

        DB::table('product_variants')->insert($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('product_variants')->insert($row);
    }
}
