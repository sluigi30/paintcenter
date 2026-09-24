<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TintColor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 of the tint-recipe revamp (MIXING.md): a mix is ONE line — a base
 * can plus colorant by the ml — priced base + a flat per-can fee.
 *
 * The rules pinned here are the ones that put the wrong paint in the box or
 * the wrong figure on the bill: the server deciding colour and price, the
 * split between what /cart/mix checks and what checkout re-checks, and the
 * recipe surviving into the order as a snapshot the counter can pour from.
 */
class TintRecipeCartTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private ProductVariant $white4L;   // 4L, #FFFFFF, cap 60 ml/L = 240 ml
    private ProductVariant $white1L;   // 1L, cap 60 ml
    private TintColor $red;            // step 2 ml
    private TintColor $black;          // step 0.5 ml

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'paint.mix.fee' => 100,
            'paint.mix.default_max_tint_ml_per_liter' => 60.0,
        ]);

        $this->customer = User::create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'mixer@example.test',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'phone' => null,   // keeps SmsService out of the checkout path
        ]);

        $base = Product::create([
            'brand_id' => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name' => 'Tint Base',
            'is_mixing_base' => true,
        ]);
        $base->categories()->attach(Category::create(['category_name' => 'Latex'])->id);

        $make = fn (string $size, int $price) => $base->variants()->create([
            'color_name' => 'White Base',
            'hex_code' => '#FFFFFF',
            'size_volume' => $size,
            'price' => $price,
            'stock' => 10,
            'low_stock_threshold' => 2,
        ]);

        $this->white4L = $make('4L', 1100);
        $this->white1L = $make('1L', 330);

        $this->red = TintColor::create(['name' => 'Oxide Red', 'hex_code' => '#9B3A2A', 'step_ml' => 2, 'sort_order' => 1]);
        $this->black = TintColor::create(['name' => 'Carbon Black', 'hex_code' => '#1A1A1A', 'step_ml' => 0.5, 'sort_order' => 2]);
    }

    private function mix(array $overrides = [])
    {
        return $this->actingAs($this->customer)->postJson('/api/cart/mix', array_replace([
            'base_variant_id' => $this->white4L->id,
            'quantity' => 1,
            'tints' => [['tint_color_id' => $this->red->id, 'ml' => 20]],
            'mix_color_name' => 'Warm Terracotta',
        ], $overrides));
    }

    private function checkout()
    {
        return $this->actingAs($this->customer)->postJson('/api/orders', [
            'order_type' => 'pickup',
            'payment_method' => 'gcash',
        ]);
    }

    // -------------------------------------------------------
    // Browsing the bench
    // -------------------------------------------------------

    public function test_bases_lists_only_cans_the_mix_endpoint_would_accept(): void
    {
        $this->white1L->update(['hex_code' => null]);            // no colour to preview from
        Product::create(['brand_id' => Brand::first()->id, 'name' => 'Finished Paint'])
            ->variants()->create(['color_name' => 'Red', 'hex_code' => '#CC0000', 'size_volume' => '4L', 'price' => 900, 'stock' => 5]);

        $response = $this->getJson('/api/mix/bases')->assertOk();

        $this->assertSame(100.0, (float) $response->json('fee'));
        $this->assertSame(['Tint Base'], array_column($response->json('bases'), 'name'));
        $this->assertSame([$this->white4L->id], array_column($response->json('bases.0.variants'), 'id'));
        $this->assertSame(240.0, (float) $response->json('bases.0.variants.0.max_tint_ml'));
    }

    public function test_tints_lists_available_presets_in_order_with_their_calibration(): void
    {
        TintColor::create(['name' => 'Gone', 'hex_code' => '#00FF00', 'is_archived' => true]);

        $tints = $this->getJson('/api/mix/tints')->assertOk()->json('tints');

        $this->assertSame(['Oxide Red', 'Carbon Black'], array_column($tints, 'name'));
        $this->assertEquals(0.5, $tints[1]['step_ml']);
        $this->assertArrayHasKey('tint_strength', $tints[0],
            'The app previews locally and must use the calibration the server predicts with.');
    }

    // -------------------------------------------------------
    // Adding a mix
    // -------------------------------------------------------

    public function test_a_recipe_is_one_cart_line_priced_base_plus_fee_per_can(): void
    {
        $response = $this->mix(['quantity' => 2])->assertOk();

        $this->assertSame(1, CartItem::count(), 'A tint recipe is ONE line, not a group.');

        $line = $response->json('items.0');
        $this->assertTrue($line['is_recipe']);
        $this->assertTrue($line['is_custom']);
        $this->assertEquals(1200, $line['price'], 'Unit price is base 1100 + fee 100.');
        $this->assertEquals(2400, $line['subtotal']);
        $this->assertSame([['tint_color_id' => $this->red->id, 'name' => 'Oxide Red', 'hex' => '#9B3A2A', 'ml' => 20]],
            array_map(fn ($r) => array_replace($r, ['ml' => (int) $r['ml']]), $line['mix_recipe']));
        $this->assertNotSame('#FFFFFF', $line['hex_code'], 'The swatch is the predicted mix, not the base.');
    }

    public function test_the_server_computes_the_colour_and_ignores_a_client_hex(): void
    {
        $this->mix(['custom_hex' => '#000000', 'hex' => '#000000'])->assertOk();

        $this->assertNotSame('#000000', CartItem::first()->custom_hex);
    }

    public function test_more_colorant_makes_a_deeper_colour(): void
    {
        $this->mix(['tints' => [['tint_color_id' => $this->red->id, 'ml' => 10]]]);
        $this->mix(['tints' => [['tint_color_id' => $this->red->id, 'ml' => 200]]]);

        [$light, $deep] = CartItem::orderBy('id')->pluck('custom_hex')->all();

        $this->assertGreaterThan(
            hexdec(substr($deep, 3, 2)),
            hexdec(substr($light, 3, 2)),
            'Green falls as oxide red is added to white.',
        );
    }

    public function test_duplicate_tints_are_summed_into_one_recipe_row(): void
    {
        $this->mix(['tints' => [
            ['tint_color_id' => $this->red->id, 'ml' => 4],
            ['tint_color_id' => $this->red->id, 'ml' => 6],
        ]])->assertOk();

        $recipe = CartItem::first()->mix_recipe;
        $this->assertCount(1, $recipe);
        $this->assertEquals(10, $recipe[0]['ml']);
    }

    public function test_a_mix_with_no_colour_is_refused(): void
    {
        $this->mix(['tints' => []])->assertStatus(422);
        $this->assertSame(0, CartItem::count());
    }

    public function test_amounts_must_be_whole_steps_of_the_preset(): void
    {
        $this->mix(['tints' => [['tint_color_id' => $this->red->id, 'ml' => 3]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Oxide Red is added in steps of 2 ml.');

        $this->mix(['tints' => [['tint_color_id' => $this->black->id, 'ml' => 1.5]]])->assertOk();
    }

    public function test_the_cap_scales_with_the_can(): void
    {
        // 240 ml fits a 4L can; the same recipe overfills a 1L can (cap 60).
        $recipe = ['tints' => [['tint_color_id' => $this->red->id, 'ml' => 200]]];

        $this->mix($recipe)->assertOk();
        $this->mix($recipe + ['base_variant_id' => $this->white1L->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That is more colour than a 1L can holds — at most 60 ml in total.');
    }

    public function test_the_cap_is_on_the_total_not_each_colour(): void
    {
        $this->mix([
            'base_variant_id' => $this->white1L->id,
            'tints' => [
                ['tint_color_id' => $this->red->id, 'ml' => 40],
                ['tint_color_id' => $this->black->id, 'ml' => 30],
            ],
        ])->assertStatus(422);
    }

    public function test_an_unavailable_preset_is_refused_by_name(): void
    {
        $this->red->update(['is_archived' => true]);

        $this->mix()->assertStatus(422)
            ->assertJsonPath('message', 'Oxide Red is no longer available for mixing. Please choose another colour.');
    }

    public function test_only_a_mixing_base_can_be_mixed_into(): void
    {
        $plain = Product::create(['brand_id' => Brand::first()->id, 'name' => 'Finished Paint'])
            ->variants()->create(['color_name' => 'White', 'hex_code' => '#FFFFFF', 'size_volume' => '4L', 'price' => 900, 'stock' => 5]);

        $this->mix(['base_variant_id' => $plain->id])->assertStatus(422);
    }

    public function test_quantity_beyond_stock_is_refused(): void
    {
        $this->mix(['quantity' => 11])->assertStatus(422);
    }

    public function test_an_old_app_posting_pints_is_told_to_update(): void
    {
        // Pint pouring was retired 2026-09-24. A build from before then posts
        // {base, tints[product_variant_id,count]}; it gets a plain instruction,
        // not a validation error about fields the customer never saw.
        $this->actingAs($this->customer)->postJson('/api/cart/mix', [
            'base' => ['product_variant_id' => $this->white4L->id, 'count' => 1],
            'tints' => [['product_variant_id' => $this->white1L->id, 'count' => 1]],
        ])->assertStatus(422)->assertJsonPath('message', 'Mixing has changed. Please update the app to mix your colour.');

        $this->assertSame(0, CartItem::count());
    }

    public function test_a_colour_sent_to_the_plain_cart_is_refused_not_dropped(): void
    {
        $paint = Product::create(['brand_id' => Brand::first()->id, 'name' => 'Finished Paint'])
            ->variants()->create(['color_name' => 'White', 'hex_code' => '#FFFFFF', 'size_volume' => '4L', 'price' => 900, 'stock' => 5]);

        $this->actingAs($this->customer)->postJson('/api/cart/add', [
            'product_variant_id' => $paint->id, 'quantity' => 1, 'custom_hex' => '#4F7942',
        ])->assertStatus(422);

        $this->assertSame(0, CartItem::count(), 'Selling the can without the colour would be the wrong paint.');
    }

    // -------------------------------------------------------
    // In the cart
    // -------------------------------------------------------

    public function test_a_recipe_line_quantity_can_be_changed(): void
    {
        $this->mix();
        $line = CartItem::first();

        $this->actingAs($this->customer)->putJson("/api/cart/{$line->id}", ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 3);
    }

    public function test_the_cart_charges_the_current_fee_not_the_one_carted(): void
    {
        $this->mix();
        config(['paint.mix.fee' => 150]);

        $this->actingAs($this->customer)->getJson('/api/cart')
            ->assertJsonPath('items.0.price', 1250);
    }

    // -------------------------------------------------------
    // Checkout
    // -------------------------------------------------------

    public function test_checkout_snapshots_the_recipe_and_deducts_only_the_base(): void
    {
        $this->mix(['quantity' => 2, 'tints' => [
            ['tint_color_id' => $this->red->id, 'ml' => 20],
            ['tint_color_id' => $this->black->id, 'ml' => 1.5],
        ]]);
        $this->checkout()->assertCreated();

        $item = OrderItem::sole();
        $this->assertTrue($item->is_recipe);
        $this->assertEquals(1200, $item->unit_price);
        $this->assertEquals(100, $item->tint_fee);
        $this->assertSame(['Oxide Red', 'Carbon Black'], array_column($item->mix_recipe, 'name'));
        $this->assertSame(8, $this->white4L->fresh()->stock);
        $this->assertSame(0, CartItem::count());

        // A preset edited after the order must not rewrite it.
        $this->red->update(['name' => 'Renamed Red', 'hex_code' => '#FF0000']);
        $this->assertSame('Oxide Red', $item->fresh()->mix_recipe[0]['name']);
    }

    public function test_checkout_refuses_a_recipe_whose_preset_was_withdrawn_and_keeps_the_cart(): void
    {
        $this->mix();
        $this->red->update(['is_archived' => true]);

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Oxide Red is no longer available for mixing. Please edit your mix before checking out.')
            ->assertJsonPath('cart_item_id', CartItem::first()->id);

        $this->assertSame(0, Order::count());
        $this->assertSame(1, CartItem::count(), 'The cart is left for the customer to edit.');
        $this->assertSame(10, $this->white4L->fresh()->stock);
    }

    public function test_checkout_refuses_a_recipe_whose_base_is_no_longer_a_mixing_base(): void
    {
        $this->mix();
        $this->white4L->product->update(['is_archived' => true]);

        $this->checkout()->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_does_not_re_check_step_or_cap(): void
    {
        $this->mix(['tints' => [['tint_color_id' => $this->red->id, 'ml' => 200]]]);

        // The store changes its mind after the mix was carted.
        $this->red->update(['step_ml' => 5]);
        $this->white4L->update(['max_tint_ml_per_liter' => 10]);

        $this->checkout()->assertCreated();
    }

    public function test_checkout_charges_the_fee_as_of_checkout(): void
    {
        $this->mix();
        config(['paint.mix.fee' => 150]);

        $this->checkout()->assertCreated();

        $this->assertEquals(1250, OrderItem::sole()->unit_price);
        $this->assertEquals(1250, Order::sole()->total_amount);
    }

    public function test_a_mixed_order_is_cancellable_only_while_pending(): void
    {
        $this->mix();
        $this->checkout()->assertCreated();

        $order = Order::sole();
        $this->assertTrue($order->has_custom_items, 'Mixed paint cannot be un-mixed.');
    }

    // -------------------------------------------------------
    // Proposing a recipe for a colour
    // -------------------------------------------------------

    private function deepBase4L(): ProductVariant
    {
        return $this->white4L->product->variants()->create([
            'color_name' => 'Deep Base', 'hex_code' => '#E6E4DC', 'size_volume' => '4L',
            'max_tint_ml_per_liter' => 120, 'price' => 1220, 'stock' => 10, 'low_stock_threshold' => 2,
        ]);
    }

    public function test_solve_proposes_a_recipe_for_every_base_of_the_size(): void
    {
        $this->deepBase4L();

        $response = $this->getJson('/api/mix/solve?hex=%23E7B8AE&liters=4')->assertOk();

        $this->assertSame('#E7B8AE', $response->json('target'));
        $this->assertCount(2, $response->json('results'), 'One proposal per 4L base; the 1L can is not offered.');
        $response->assertJsonStructure(['results' => [[
            'base_variant_id', 'base_name', 'size_volume', 'hex', 'delta_e', 'band', 'total_ml',
            'recipe' => [['tint_color_id', 'name', 'hex', 'ml']],
        ]]]);
    }

    public function test_every_proposed_recipe_is_accepted_by_the_cart_as_is(): void
    {
        $this->deepBase4L();

        foreach (['#E7B8AE', '#C47A52', '#8A8F96', '#F2E6D8'] as $hex) {
            $results = $this->getJson('/api/mix/solve?'.http_build_query(['hex' => $hex, 'liters' => 4]))
                ->assertOk()->json('results');

            foreach ($results as $proposal) {
                if ($proposal['recipe'] === []) {
                    continue;   // the base alone is the answer; there is nothing to mix
                }

                $this->mix([
                    'base_variant_id' => $proposal['base_variant_id'],
                    'tints' => array_map(fn ($r) => ['tint_color_id' => $r['tint_color_id'], 'ml' => $r['ml']], $proposal['recipe']),
                ])->assertOk();

                // And the cart predicts the colour the proposal promised.
                $this->assertSame($proposal['hex'], CartItem::latest('id')->first()->custom_hex);
            }
        }
    }

    public function test_bases_are_ranked_by_band_then_by_least_colorant(): void
    {
        $this->deepBase4L();

        $results = $this->getJson('/api/mix/solve?hex=%23E7B8AE&liters=4')->json('results');
        $rank = ['match' => 0, 'close' => 1, 'near' => 2, 'nearest' => 3];

        for ($i = 1; $i < count($results); $i++) {
            $a = $results[$i - 1];
            $b = $results[$i];

            $this->assertLessThanOrEqual(0,
                [$rank[$a['band']], $a['total_ml']] <=> [$rank[$b['band']], $b['total_ml']],
                'A better band first; within a band, the recipe that needs less colorant.');
        }
    }

    public function test_solve_leaves_out_withdrawn_presets(): void
    {
        $this->red->update(['is_archived' => true]);

        $results = $this->getJson('/api/mix/solve?hex=%23E7B8AE&liters=4')->assertOk()->json('results');

        foreach ($results as $proposal) {
            $this->assertNotContains($this->red->id, array_column($proposal['recipe'], 'tint_color_id'));
        }
    }

    public function test_solve_refuses_a_size_with_no_base_and_a_non_colour(): void
    {
        $this->getJson('/api/mix/solve?hex=%23E7B8AE&liters=16')->assertStatus(422);
        $this->getJson('/api/mix/solve?hex=notacolour&liters=4')->assertStatus(422);
    }

    // -------------------------------------------------------
    // Is a colour already on the shelf?
    // -------------------------------------------------------

    public function test_stocked_names_a_finished_paint_that_matches_and_never_a_base(): void
    {
        $paint = Product::create(['brand_id' => Brand::first()->id, 'name' => 'Finished Latex']);
        $can = $paint->variants()->create([
            'color_name' => 'Sage', 'hex_code' => '#9CAF88', 'size_volume' => '4L', 'price' => 900, 'stock' => 5,
        ]);

        $results = $this->getJson('/api/colors/stocked?'.http_build_query(['hex' => ['#9CAF88', '#FFFFFF', '#1B2A4A']]))
            ->assertOk()->json('results');

        $this->assertSame($paint->id, $results[0]['match']['product_id']);
        $this->assertSame($can->id, $results[0]['match']['product_variant_id']);
        $this->assertNull($results[1]['match'], 'The only exact white is a mixing base, which is sold only mixed.');
        $this->assertNull($results[2]['match'], 'A near miss is not the colour asked for.');
    }

    public function test_stocked_refuses_a_non_colour(): void
    {
        $this->getJson('/api/colors/stocked?hex[]=nope')->assertStatus(422);
    }

    // -------------------------------------------------------
    // Reports
    // -------------------------------------------------------

    public function test_reports_count_recipe_mixes_and_the_colorant_poured(): void
    {
        $this->mix(['quantity' => 2, 'tints' => [
            ['tint_color_id' => $this->red->id, 'ml' => 20],
            ['tint_color_id' => $this->black->id, 'ml' => 1.5],
        ]]);
        $this->checkout()->assertCreated();

        $colors = (new \App\Services\Reports\Metrics\ColorMetrics(
            \App\Services\Reports\ReportPeriod::make(now()->subDay()->toDateString(), now()->toDateString())
        ))->get()['current'];

        $this->assertSame(1, $colors['custom'][0]['mixes'], 'One recipe line is one mix.');
        $this->assertSame(2, $colors['custom'][0]['quantity']);

        // ml per can × cans, most used first.
        $this->assertSame(['Oxide Red', 'Carbon Black'], array_column($colors['colorants'], 'label'));
        $this->assertEquals(40.0, $colors['colorants'][0]['ml']);
        $this->assertEquals(3.0, $colors['colorants'][1]['ml']);
    }

    // -------------------------------------------------------
    // What the counter reads
    // -------------------------------------------------------

    public function test_the_counter_card_spells_out_the_recipe_per_can_and_per_batch(): void
    {
        $this->mix(['quantity' => 2, 'tints' => [
            ['tint_color_id' => $this->red->id, 'ml' => 20],
            ['tint_color_id' => $this->black->id, 'ml' => 1.5],
        ]]);
        $this->checkout()->assertCreated();

        $method = new \ReflectionMethod(OrderResource::class, 'mixSheet');
        $html = (string) $method->invoke(null, Order::sole());

        $this->assertStringContainsString('TINT TO ORDER', $html);
        $this->assertStringContainsString('Warm Terracotta', $html);
        $this->assertStringContainsString('White Base', $html);
        $this->assertStringContainsString('20 ml', $html);   // per can
        $this->assertStringContainsString('40 ml', $html);   // for both cans
        $this->assertStringContainsString('1.5 ml', $html);
        $this->assertStringContainsString('21.5 ml', $html); // total per can
        $this->assertStringContainsString('ONE batch', $html);
    }

    public function test_the_admin_order_screen_renders_a_recipe_order(): void
    {
        $this->mix();
        $this->checkout()->assertCreated();

        $admin = User::create([
            'first_name' => 'Test', 'last_name' => 'Admin', 'email' => 'admin@example.test',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);

        // The customer's API requests left them signed in on `web`, and
        // auth:sanctum made sanctum the DEFAULT guard — so a bare actingAs()
        // would sign the admin into the guard Filament does not read.
        $this->app['auth']->forgetGuards();

        $this->actingAs($admin, 'web')->get('/admin/orders/'.Order::sole()->id.'/edit')
            ->assertOk()
            ->assertSee('TINT TO ORDER');
    }
}
