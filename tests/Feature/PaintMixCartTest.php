<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Buying a customer-composed mix end to end.
 *
 * The rules pinned here are the ones that hand over the WRONG PHYSICAL PAINT
 * when they break: the recipe posting whole or not at all, the cart merge key
 * keeping ordinary cans out of a mix, a mix checking out whole, and the recipe
 * surviving into the order for the counter to read.
 *
 * See MIXING.md.
 */
class PaintMixCartTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private ProductVariant $whiteBase;   // 4L  #FFFFFF
    private ProductVariant $redPint;     // Pint #CC2222
    private ProductVariant $bluePint;    // Pint #2244AA
    private ProductVariant $plainLitre;  // 1L  #336699 — not a pint
    private ProductVariant $noHexPint;   // Pint, no hex on file

    protected function setUp(): void
    {
        parent::setUp();

        // UserFactory still sets a `name` column that no longer exists.
        $this->customer = User::create([
            'first_name' => 'Test',
            'last_name'  => 'Customer',
            'email'      => 'mixer@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'customer',
            'phone'      => null,   // keeps SmsService out of the checkout path
        ]);

        $product = Product::create([
            'brand_id' => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name'     => 'Testbrand Latex',
        ]);
        $product->categories()->attach(Category::create(['category_name' => 'Latex'])->id);

        $make = fn (string $name, string $size, ?string $hex, int $stock = 50) => ProductVariant::create([
            'product_id'  => $product->id,
            'color_name'  => $name,
            'color_code'  => '',
            'hex_code'    => $hex,
            'size_volume' => $size,
            'price'       => 100,
            'stock'       => $stock,
        ]);

        $this->whiteBase  = $make('White',  '4L',   '#FFFFFF');
        $this->redPint    = $make('Red',    'Pint', '#CC2222');
        $this->bluePint   = $make('Blue',   'Pint', '#2244AA');
        $this->plainLitre = $make('Teal',   '1L',   '#336699');
        $this->noHexPint  = $make('Mystery', 'Pint', null);
    }

    private function recipe(array $overrides = []): array
    {
        return array_replace([
            'base'  => ['product_variant_id' => $this->whiteBase->id, 'count' => 1],
            'tints' => [['product_variant_id' => $this->redPint->id, 'count' => 1]],
        ], $overrides);
    }

    private function mix(array $overrides = [])
    {
        return $this->actingAs($this->customer)
            ->postJson('/api/cart/mix', $this->recipe($overrides));
    }

    // -------------------------------------------------------
    // The happy path
    // -------------------------------------------------------

    public function test_a_mix_adds_every_ingredient_as_its_own_line(): void
    {
        $this->mix(['tints' => [
            ['product_variant_id' => $this->redPint->id,  'count' => 2],
            ['product_variant_id' => $this->bluePint->id, 'count' => 1],
        ]])->assertOk();

        $lines = CartItem::where('user_id', $this->customer->id)->get();

        // The customer buys everything that goes in the can.
        $this->assertCount(3, $lines);
        $this->assertSame(1, $lines->where('mix_role', 'base')->count());
        $this->assertSame(2, $lines->where('mix_role', 'tint')->count());
        $this->assertSame(2, $lines->firstWhere('product_variant_id', $this->redPint->id)->quantity);

        // One recipe, one group, one predicted colour on every line.
        $this->assertCount(1, $lines->pluck('mix_group')->unique());
        $this->assertCount(1, $lines->pluck('custom_hex')->unique());
        $this->assertNotNull($lines->first()->custom_hex);
    }

    public function test_the_predicted_colour_is_computed_by_the_server(): void
    {
        // The client sends no hex at all — it is not trusted with the value
        // that decides what goes in the can.
        $this->mix()->assertOk();

        // 4L white + one pint of #CC2222, from the documented reach table.
        $this->assertSame(
            '#ED7878',
            CartItem::where('user_id', $this->customer->id)->first()->custom_hex
        );
    }

    public function test_mix_liters_is_the_volume_of_one_can_not_the_line(): void
    {
        $this->mix(['tints' => [['product_variant_id' => $this->redPint->id, 'count' => 3]]])
            ->assertOk();

        $tint = CartItem::where('mix_role', 'tint')->first();

        // Three pints on one line still records 0.473 — the line contributes
        // quantity * mix_liters. Storing 1.419 here would make the recipe
        // unreadable at the counter and would double-count on a re-read.
        $this->assertSame(3, $tint->quantity);
        $this->assertEqualsWithDelta(0.473, $tint->mix_liters, 0.0001);
    }

    public function test_a_mix_carries_no_tint_fee(): void
    {
        // The fee paid for colourant out of a dispenser. Here the customer
        // buys every ingredient outright, so charging it too is charging
        // twice for the same paint.
        $this->whiteBase->update(['tint_fee' => 75]);
        $this->redPint->update(['tint_fee' => 75]);

        $this->mix()->assertOk();

        $this->assertSame(
            [0.0],
            CartItem::where('user_id', $this->customer->id)->pluck('tint_fee')->unique()->map(fn ($f) => (float) $f)->all()
        );
    }

    public function test_duplicate_tints_are_summed_rather_than_refused(): void
    {
        // A double-tap or a client retry must not fail a valid recipe.
        $this->mix(['tints' => [
            ['product_variant_id' => $this->redPint->id, 'count' => 2],
            ['product_variant_id' => $this->redPint->id, 'count' => 3],
        ]])->assertOk();

        $tints = CartItem::where('mix_role', 'tint')->get();

        $this->assertCount(1, $tints, 'One colourant is one line on the sheet.');
        $this->assertSame(5, $tints->first()->quantity);
    }

    public function test_the_same_paint_can_be_both_base_and_tint(): void
    {
        // Mathematically valid and operationally harmless. Normalisation
        // folds duplicates WITHIN tints, never across roles, or the recipe
        // stops being readable.
        $this->mix([
            'base'  => ['product_variant_id' => $this->redPint->id, 'count' => 2],
            'tints' => [['product_variant_id' => $this->redPint->id, 'count' => 1]],
        ])->assertOk();

        $lines = CartItem::where('product_variant_id', $this->redPint->id)->get();

        $this->assertCount(2, $lines);
        $this->assertEqualsCanonicalizing(['base', 'tint'], $lines->pluck('mix_role')->all());
    }

    // -------------------------------------------------------
    // Eligibility — settled on the server, not in the picker
    // -------------------------------------------------------

    public function test_a_tint_must_be_a_pint(): void
    {
        $this->mix(['tints' => [['product_variant_id' => $this->plainLitre->id, 'count' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'pint'));

        $this->assertSame(0, CartItem::count());
    }

    public function test_a_base_may_be_any_size(): void
    {
        // The whole design is a 4L base with pints poured in, so the base is
        // deliberately NOT restricted to pints.
        $this->mix(['base' => ['product_variant_id' => $this->plainLitre->id, 'count' => 1]])
            ->assertOk();
    }

    public function test_a_paint_with_no_colour_on_file_cannot_be_mixed(): void
    {
        $this->mix(['tints' => [['product_variant_id' => $this->noHexPint->id, 'count' => 1]]])
            ->assertStatus(422);

        $this->assertSame(0, CartItem::count());
    }

    public function test_an_archived_paint_cannot_be_mixed(): void
    {
        $this->redPint->update(['is_archived' => true]);

        $this->mix()->assertStatus(422);
        $this->assertSame(0, CartItem::count());
    }

    public function test_a_recipe_needs_at_least_one_colour(): void
    {
        // A base on its own is just a can of paint; it belongs in /cart/add.
        $this->actingAs($this->customer)
            ->postJson('/api/cart/mix', ['base' => ['product_variant_id' => $this->whiteBase->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tints');
    }

    public function test_the_number_of_colours_is_capped(): void
    {
        config(['paint.mix.max_tints' => 2]);

        $this->mix(['tints' => [
            ['product_variant_id' => $this->redPint->id,  'count' => 1],
            ['product_variant_id' => $this->bluePint->id, 'count' => 1],
            ['product_variant_id' => $this->plainLitre->id, 'count' => 1],
        ]])->assertStatus(422);
    }

    // -------------------------------------------------------
    // All or nothing
    // -------------------------------------------------------

    public function test_a_recipe_short_of_stock_adds_nothing_at_all(): void
    {
        $this->bluePint->update(['stock' => 1]);

        $this->mix(['tints' => [
            ['product_variant_id' => $this->redPint->id,  'count' => 1],
            ['product_variant_id' => $this->bluePint->id, 'count' => 5],
        ]])->assertStatus(422);

        // Half a recipe in the cart is not a partial order, it is a different
        // colour. Nothing may survive the rejection.
        $this->assertSame(0, CartItem::count());
    }

    public function test_stock_is_counted_across_both_roles_of_one_variant(): void
    {
        // Two cans wanted as the base and three more as a colourant is five
        // cans off the same shelf. Checking each role alone would wave through
        // a recipe the shop cannot fill.
        $this->redPint->update(['stock' => 4]);

        $this->mix([
            'base'  => ['product_variant_id' => $this->redPint->id, 'count' => 2],
            'tints' => [['product_variant_id' => $this->redPint->id, 'count' => 3]],
        ])->assertStatus(422);

        $this->assertSame(0, CartItem::count());
    }

    public function test_adding_a_mix_does_not_reserve_stock(): void
    {
        // Consistent with /cart/add: the cart checks availability, checkout
        // takes it under lock. Anything else would let an abandoned cart hold
        // paint hostage.
        $before = $this->redPint->stock;

        $this->mix()->assertOk();

        $this->assertSame($before, $this->redPint->fresh()->stock);
    }

    // -------------------------------------------------------
    // Keeping ordinary cans out of a recipe
    // -------------------------------------------------------

    public function test_an_ordinary_can_never_merges_into_a_mix(): void
    {
        $this->mix()->assertOk();

        $mixLine = CartItem::where('mix_role', 'tint')->first();

        // Same variant, bought on its own. A mix line carries a custom_hex
        // too, so without the mix_group guard this would merge INTO the
        // recipe — silently changing its proportions, and with them the
        // colour the customer approved on screen.
        $this->actingAs($this->customer)
            ->postJson('/api/cart/add', [
                'product_variant_id' => $this->redPint->id,
                'quantity'           => 1,
            ])->assertOk();

        $this->assertSame(1, $mixLine->fresh()->quantity, 'The recipe must be untouched.');
        $this->assertSame(
            3,
            CartItem::where('user_id', $this->customer->id)->count(),
            'The loose can is its own line.'
        );
    }

    public function test_two_mixes_of_the_same_colour_stay_apart(): void
    {
        $this->mix()->assertOk();
        $this->mix()->assertOk();

        $groups = CartItem::where('user_id', $this->customer->id)->pluck('mix_group')->unique();

        // Same recipe, same predicted hex, two separate batches. Merging them
        // is the bug the custom_hex clause was added to fix: charged for two,
        // delivered one.
        $this->assertCount(2, $groups);
        $this->assertSame(4, CartItem::count());
    }

    public function test_the_mix_group_is_never_taken_from_the_client(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/cart/mix', $this->recipe(['mix_group' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']))
            ->assertOk();

        $this->assertNotSame(
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            CartItem::first()->mix_group,
            'A supplied group would let a request post lines into someone else’s mix.'
        );
    }

    // -------------------------------------------------------
    // Editing a mix in the cart
    // -------------------------------------------------------

    public function test_one_ingredient_cannot_be_re_quantified(): void
    {
        $this->mix()->assertOk();
        $line = CartItem::where('mix_role', 'tint')->first();

        // Changing an ingredient changes the COLOUR, not the amount.
        $this->actingAs($this->customer)
            ->putJson("/api/cart/{$line->id}", ['quantity' => 5])
            ->assertStatus(422);

        $this->assertSame(1, $line->fresh()->quantity);
    }

    public function test_removing_one_ingredient_removes_the_whole_mix(): void
    {
        $this->mix(['tints' => [
            ['product_variant_id' => $this->redPint->id,  'count' => 1],
            ['product_variant_id' => $this->bluePint->id, 'count' => 1],
        ]])->assertOk();

        $this->assertSame(3, CartItem::count());

        // A base with nothing to colour it, or colourant with no base, is a
        // recipe that cannot be made.
        $this->actingAs($this->customer)
            ->deleteJson('/api/cart/' . CartItem::where('mix_role', 'tint')->first()->id)
            ->assertOk();

        $this->assertSame(0, CartItem::count());
    }

    public function test_removing_a_loose_can_leaves_a_mix_alone(): void
    {
        $this->mix()->assertOk();

        $this->actingAs($this->customer)->postJson('/api/cart/add', [
            'product_variant_id' => $this->plainLitre->id,
            'quantity'           => 1,
        ])->assertOk();

        $loose = CartItem::whereNull('mix_group')->first();

        $this->actingAs($this->customer)->deleteJson("/api/cart/{$loose->id}")->assertOk();

        $this->assertSame(2, CartItem::count());
    }

    // -------------------------------------------------------
    // What the cart tells the app
    // -------------------------------------------------------

    public function test_the_cart_exposes_the_grouping(): void
    {
        $this->mix()->assertOk();

        $this->actingAs($this->customer)->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('has_mix', true)
            ->assertJsonStructure([
                'items' => [['mix_group', 'mix_role', 'mix_liters', 'is_mixed', 'custom_hex', 'mix_hex', 'mix_name']],
            ]);
    }

    public function test_each_ingredient_keeps_its_own_identity_in_the_cart(): void
    {
        $this->mix(['mix_color_name' => "Ella's Room"])->assertOk();

        $items = collect($this->actingAs($this->customer)->getJson('/api/cart')->json('items'));

        $base = $items->firstWhere('mix_role', 'base');
        $tint = $items->firstWhere('mix_role', 'tint');

        // Every line is a real can the customer is buying, so each row has to
        // say WHICH. Serving the mix's name and colour on all of them showed
        // three identical rows called "Ella's Room" and nothing to tell them
        // apart — you could not see what you were paying for.
        $this->assertSame('White', $base['color_name']);
        $this->assertSame('#FFFFFF', $base['hex_code']);
        $this->assertSame('Red', $tint['color_name']);
        $this->assertSame('#CC2222', $tint['hex_code']);

        // The mixed colour belongs to the group, and is shown once above it.
        $this->assertSame('#ED7878', $base['mix_hex']);
        $this->assertSame('#ED7878', $tint['mix_hex']);
        $this->assertSame("Ella's Room", $tint['mix_name']);
    }

    public function test_an_ordinary_custom_line_still_serves_its_colour_as_before(): void
    {
        // The mix carve-out must not disturb the shipped custom-colour flow,
        // where the line's colour IS the custom hex.
        $custom = Product::create([
            'brand_id'        => Brand::first()->id,
            'name'            => 'Testbrand Latex — Custom Colour',
            'is_custom_color' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id'  => $custom->id,
            'color_name'  => '',
            'color_code'  => '',
            'size_volume' => '4L',
            'base_code'   => '',
            'price'       => 100,
            'stock'       => 10,
        ]);

        $this->actingAs($this->customer)->postJson('/api/cart/add', [
            'product_variant_id' => $variant->id,
            'quantity'           => 1,
            'custom_hex'         => '#C8D5C0',
            'custom_color_name'  => 'Pale Sage',
        ])->assertOk();

        $line = collect($this->actingAs($this->customer)->getJson('/api/cart')->json('items'))
            ->firstWhere('product_variant_id', $variant->id);

        $this->assertSame('#C8D5C0', $line['hex_code']);
        $this->assertSame('Pale Sage', $line['color_name']);
        $this->assertNull($line['mix_hex']);
        $this->assertFalse($line['is_mixed']);
    }

    // -------------------------------------------------------
    // Checkout
    // -------------------------------------------------------

    private function checkout(?array $ids = null)
    {
        return $this->actingAs($this->customer)->postJson('/api/orders', array_filter([
            'order_type'     => 'pickup',
            'payment_method' => 'gcash',
            'cart_item_ids'  => $ids,
        ]));
    }

    public function test_the_recipe_survives_into_the_order(): void
    {
        $this->mix(['tints' => [['product_variant_id' => $this->redPint->id, 'count' => 2]]])
            ->assertOk();

        $this->checkout()->assertCreated();

        $items = OrderItem::all();
        $this->assertCount(2, $items);

        // Without these the counter receives a list of cans and no
        // instruction to pour them together.
        $this->assertCount(1, $items->pluck('mix_group')->unique());
        $this->assertNotNull($items->first()->mix_group);
        $this->assertEqualsCanonicalizing(['base', 'tint'], $items->pluck('mix_role')->all());
        // Two pints of #CC2222 into 4L of white, from the reach table.
        $this->assertSame('#E75E5E', $items->first()->custom_hex);

        $tint = $items->firstWhere('mix_role', 'tint');
        $this->assertEqualsWithDelta(0.473, $tint->mix_liters, 0.0001);
        $this->assertSame(2, $tint->quantity);
    }

    public function test_a_mix_cannot_be_half_checked_out(): void
    {
        $this->mix()->assertOk();

        $baseLine = CartItem::where('mix_role', 'base')->first();

        // Ticking the base alone would order plain paint against a swatch of
        // the mixed colour.
        $this->checkout([$baseLine->id])->assertStatus(422);

        $this->assertSame(0, OrderItem::count());
        $this->assertSame(2, CartItem::count(), 'A refused checkout empties nothing.');
    }

    public function test_a_whole_mix_can_be_checked_out_alongside_other_lines(): void
    {
        $this->mix()->assertOk();

        $mixIds = CartItem::whereNotNull('mix_group')->pluck('id')->all();

        $this->checkout($mixIds)->assertCreated();
        $this->assertSame(2, OrderItem::count());
    }

    public function test_a_mixed_order_can_only_be_cancelled_before_it_is_made(): void
    {
        $this->mix()->assertOk();
        $this->checkout()->assertCreated();

        $order = \App\Models\Order::first();

        // custom_hex on every line is what earns this for free: mixed paint
        // cannot be un-mixed any more than tinted paint can.
        $this->assertTrue($order->has_custom_items);
        $this->assertTrue($order->can_cancel);

        $order->update(['status' => 'processing']);
        $this->assertFalse($order->fresh()->can_cancel);
    }

    public function test_the_counter_gets_a_recipe_not_a_list_of_cans(): void
    {
        $this->mix([
            'tints' => [
                ['product_variant_id' => $this->redPint->id,  'count' => 2],
                ['product_variant_id' => $this->bluePint->id, 'count' => 1],
            ],
            'mix_color_name' => "Ella's Room",
        ])->assertOk();

        $this->checkout()->assertCreated();

        $order = \App\Models\Order::first();

        // mixSheet is what the shop actually works from. Rendered as one card
        // per line it shows three cans of the same predicted colour and no
        // instruction to combine them — which is how three wrong cans leave
        // the shop. Reflection because it is protected and has no route.
        $method = new \ReflectionMethod(\App\Filament\Resources\OrderResource::class, 'mixSheet');
        $method->setAccessible(true);
        $html = (string) $method->invoke(null, $order);

        $this->assertStringContainsString('MIX TO ORDER', $html);

        // Escaped, not raw: the customer names their own colour and that string
        // is rendered as HTML straight into the admin panel.
        $this->assertStringContainsString('Ella&#039;s Room', $html);
        $this->assertStringNotContainsString("Ella's Room", $html);
        $this->assertStringContainsString('BASE', $html);
        $this->assertStringContainsString('ADD', $html);

        // Per-can volume is what gets poured; the total is what it needs
        // pouring INTO. A 4L base plus three pints does not go back in the can.
        $this->assertStringContainsString('4.000 L', $html);
        $this->assertStringContainsString('0.473 L &times; 2', $html);
        $this->assertStringContainsString('5.419 L', $html);

        // Both warnings survive into the grouped sheet.
        $this->assertStringContainsString('ONE batch', $html);
        $this->assertStringContainsString('estimate', $html);
    }

    public function test_stock_is_taken_in_cans_at_checkout(): void
    {
        $whiteBefore = $this->whiteBase->stock;
        $redBefore   = $this->redPint->stock;

        $this->mix(['tints' => [['product_variant_id' => $this->redPint->id, 'count' => 3]]])
            ->assertOk();
        $this->checkout()->assertCreated();

        // Cans, not litres. The recipe's volume never touches inventory.
        $this->assertSame($whiteBefore - 1, $this->whiteBase->fresh()->stock);
        $this->assertSame($redBefore - 3, $this->redPint->fresh()->stock);
    }
}
