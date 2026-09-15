<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Buying a custom-mixed colour end to end.
 *
 * The rules pinned here are the ones that cost money or deliver the wrong
 * physical product when they break: the cart merge key, base validation,
 * gamut refusal, the tint fee, and the earlier cancellation deadline.
 */
class CustomColorCartTest extends TestCase
{
    use RefreshDatabase;

    private const DEEP_BURGUNDY = '#7A1F2B';  // -> deep base
    private const PALE_SAGE     = '#C8D5C0';  // -> pastel base
    private const FLUORESCENT   = '#39FF14';  // outside the paint gamut

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // UserFactory still sets a `name` column that no longer exists.
        $this->customer = User::create([
            'first_name' => 'Test',
            'last_name'  => 'Customer',
            'email'      => 'customer@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'customer',
            'phone'      => null,   // keeps SmsService out of the checkout path
        ]);
    }

    // -------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------

    private function product(bool $custom, array $variants): Product
    {
        $product = Product::create([
            'brand_id'        => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name'            => $custom ? 'Testbrand Latex — Custom Colour' : 'Testbrand Latex',
            'is_custom_color' => $custom,
        ]);

        $product->categories()->attach(Category::create(['category_name' => 'Latex'])->id);

        foreach ($variants as $v) {
            // Colour lives on the variant now. A custom-colour line leaves it
            // empty — the customer's choice arrives on the cart line instead.
            $product->variants()->create($v + [
                'color_code' => $custom ? '' : 'R-01',
                'color_name' => $custom ? '' : 'Red',
                'hex_code'   => $custom ? null : '#B01B1B',
                'price'      => 1200,
                'tint_fee'   => 0,
                'stock'      => 10,
            ]);
        }

        return $product->fresh('activeVariants');
    }

    /** One custom-colour product with 4L in each of the three bases. */
    private function customProduct(): Product
    {
        return $this->product(true, [
            ['size_volume' => '4L', 'base_code' => 'P', 'tint_fee' => 150],
            ['size_volume' => '4L', 'base_code' => 'M', 'tint_fee' => 200],
            ['size_volume' => '4L', 'base_code' => 'D', 'tint_fee' => 250],
        ]);
    }

    private function variantFor(Product $product, string $baseCode): ProductVariant
    {
        return $product->variants()->where('base_code', $baseCode)->firstOrFail();
    }

    private function addToCart(array $payload)
    {
        return $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/cart/add', $payload);
    }

    // -------------------------------------------------------
    // Choosing a colour
    // -------------------------------------------------------

    public function test_a_custom_colour_product_cannot_be_bought_without_a_colour(): void
    {
        $product = $this->customProduct();

        $this->addToCart([
            'product_variant_id' => $this->variantFor($product, 'D')->id,
            'quantity'           => 1,
        ])->assertStatus(422);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_an_unmixable_colour_is_refused_with_a_workable_alternative(): void
    {
        $product = $this->customProduct();

        $response = $this->addToCart([
            'product_variant_id' => $this->variantFor($product, 'D')->id,
            'quantity'           => 1,
            'custom_hex'         => self::FLUORESCENT,
        ])->assertStatus(422);

        $this->assertNotNull($response->json('nearest_mixable'));
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_a_colour_cannot_be_put_into_the_wrong_base(): void
    {
        $product = $this->customProduct();

        // Deep burgundy into a pastel base: not enough colorant room, and the
        // result would visibly not be the colour ordered.
        $this->addToCart([
            'product_variant_id' => $this->variantFor($product, 'P')->id,
            'quantity'           => 1,
            'custom_hex'         => self::DEEP_BURGUNDY,
        ])->assertStatus(422);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_a_ready_mixed_product_refuses_a_custom_colour(): void
    {
        // A finished red can cannot be tinted. Dropping the hex silently would
        // sell the customer a colour they did not choose.
        $product = $this->product(false, [['size_volume' => '4L']]);

        $this->addToCart([
            'product_variant_id' => $product->variants()->first()->id,
            'quantity'           => 1,
            'custom_hex'         => self::DEEP_BURGUNDY,
        ])->assertStatus(422);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_a_matching_base_is_accepted(): void
    {
        $product = $this->customProduct();

        $this->addToCart([
            'product_variant_id' => $this->variantFor($product, 'D')->id,
            'quantity'           => 2,
            'custom_hex'         => strtolower(self::DEEP_BURGUNDY),
            'custom_color_name'  => 'Ella\'s Room',
        ])->assertOk();

        $this->assertDatabaseHas('cart_items', [
            'custom_hex'        => self::DEEP_BURGUNDY,   // normalised to upper case
            'custom_color_name' => "Ella's Room",
            'quantity'          => 2,
        ]);
    }

    // -------------------------------------------------------
    // The merge key
    // -------------------------------------------------------

    /**
     * The regression this feature is most likely to ship with. Merging on the
     * variant alone would fold two different colours of the same base and size
     * into one line: the customer pays for two cans and receives two of the
     * same colour.
     */
    public function test_two_different_colours_in_the_same_size_stay_separate_lines(): void
    {
        $product = $this->customProduct();
        $deep    = $this->variantFor($product, 'D');

        $this->addToCart([
            'product_variant_id' => $deep->id,
            'quantity'           => 1,
            'custom_hex'         => self::DEEP_BURGUNDY,
        ])->assertOk();

        // Same base, same size, different colour.
        $this->addToCart([
            'product_variant_id' => $deep->id,
            'quantity'           => 1,
            'custom_hex'         => '#1F3A7A',
        ])->assertOk();

        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_the_same_colour_added_twice_merges_into_one_line(): void
    {
        $product = $this->customProduct();
        $deep    = $this->variantFor($product, 'D');

        foreach ([1, 2] as $qty) {
            $this->addToCart([
                'product_variant_id' => $deep->id,
                'quantity'           => $qty,
                'custom_hex'         => self::DEEP_BURGUNDY,
            ])->assertOk();
        }

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(3, CartItem::first()->quantity);
    }

    // -------------------------------------------------------
    // Price and presentation
    // -------------------------------------------------------

    public function test_the_tint_fee_is_charged_and_shown_broken_out(): void
    {
        $product = $this->customProduct();

        $response = $this->addToCart([
            'product_variant_id' => $this->variantFor($product, 'D')->id,
            'quantity'           => 2,
            'custom_hex'         => self::DEEP_BURGUNDY,
        ])->assertOk();

        $line = $response->json('items.0');

        $this->assertEquals(1200, $line['base_price']);
        $this->assertEquals(250,  $line['tint_fee']);
        $this->assertEquals(1450, $line['price'], 'unit price is all-in');
        $this->assertEquals(2900, $line['subtotal']);
        $this->assertEquals(2900, $response->json('total'));
    }

    /**
     * A custom line's colour rides in the SAME hex_code field a ready-mixed
     * line uses, so every swatch already in the app renders it unchanged.
     */
    public function test_the_custom_colour_is_served_through_the_existing_swatch_field(): void
    {
        $product = $this->customProduct();

        $response = $this->addToCart([
            'product_variant_id' => $this->variantFor($product, 'P')->id,
            'quantity'           => 1,
            'custom_hex'         => self::PALE_SAGE,
            'custom_color_name'  => 'Kitchen Sage',
        ])->assertOk();

        $this->assertSame(self::PALE_SAGE, $response->json('items.0.hex_code'));
        $this->assertSame('Kitchen Sage',  $response->json('items.0.color_name'));
        $this->assertTrue($response->json('items.0.is_custom'));
        $this->assertTrue($response->json('has_custom'));
    }

    // -------------------------------------------------------
    // Checkout
    // -------------------------------------------------------

    public function test_checkout_snapshots_the_colour_and_bills_the_base_stock(): void
    {
        $product = $this->customProduct();
        $deep    = $this->variantFor($product, 'D');

        $this->addToCart([
            'product_variant_id' => $deep->id,
            'quantity'           => 3,
            'custom_hex'         => self::DEEP_BURGUNDY,
            'custom_color_name'  => 'Ella\'s Room',
        ])->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/orders', [
                'order_type'     => 'pickup',
                'payment_method' => 'gcash',
            ])->assertStatus(201);

        $line = OrderItem::firstOrFail();

        $this->assertSame(self::DEEP_BURGUNDY, $line->custom_hex);
        $this->assertSame("Ella's Room", $line->custom_color_name);
        $this->assertEquals(250,  $line->tint_fee);
        $this->assertEquals(1450, $line->unit_price, 'all-in, base + tint');
        $this->assertEquals(4350, $line->subtotal);
        $this->assertTrue($line->is_custom);

        // There is no stock for a custom colour — the BASE can is what moves.
        $this->assertSame(7, $deep->fresh()->stock);
        $this->assertDatabaseHas('inventory_logs', [
            'product_variant_id' => $deep->id,
            'action_name'        => 'order_placed',
            'quantity_changed'   => -3,
        ]);
    }

    // -------------------------------------------------------
    // Cancellation
    // -------------------------------------------------------

    private function placeOrder(bool $custom): Order
    {
        $product = $custom
            ? $this->customProduct()
            : $this->product(false, [['size_volume' => '4L']]);

        $variant = $custom
            ? $this->variantFor($product, 'D')
            : $product->variants()->first();

        $this->addToCart(array_filter([
            'product_variant_id' => $variant->id,
            'quantity'           => 1,
            'custom_hex'         => $custom ? self::DEEP_BURGUNDY : null,
        ]))->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/orders', [
                'order_type'     => 'pickup',
                'payment_method' => 'gcash',
            ])->assertStatus(201);

        return Order::latest('id')->firstOrFail();
    }

    public function test_a_custom_order_can_still_be_cancelled_while_pending(): void
    {
        $order = $this->placeOrder(custom: true);

        $this->assertTrue($order->fresh()->can_cancel);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    /**
     * Mixing happens during `processing`. A tinted can cannot be un-tinted or
     * resold, so the customer's cancel window closes a status earlier than it
     * does for ready-mixed paint.
     */
    public function test_a_custom_order_cannot_be_cancelled_once_it_is_being_mixed(): void
    {
        $order = $this->placeOrder(custom: true);
        $order->update(['status' => 'processing']);

        $this->assertFalse($order->fresh()->can_cancel);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertStatus(422);

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_a_ready_mixed_order_can_still_be_cancelled_while_processing(): void
    {
        $order = $this->placeOrder(custom: false);
        $order->update(['status' => 'processing']);

        $this->assertTrue($order->fresh()->can_cancel);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    // -------------------------------------------------------
    // The colour endpoint the picker drives
    // -------------------------------------------------------

    public function test_resolve_names_the_base_without_requiring_a_login(): void
    {
        $this->getJson('/api/colors/resolve?hex=' . urlencode(self::DEEP_BURGUNDY))
            ->assertOk()
            ->assertJson([
                'hex'       => self::DEEP_BURGUNDY,
                'base_code' => 'D',
                'in_gamut'  => true,
            ]);
    }

    public function test_resolve_flags_an_unmixable_colour(): void
    {
        $response = $this->getJson('/api/colors/resolve?hex=' . urlencode(self::FLUORESCENT))
            ->assertOk();

        $this->assertFalse($response->json('in_gamut'));
        $this->assertNotNull($response->json('nearest_mixable'));
    }
}
