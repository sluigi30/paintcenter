<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue shape QA asked for on 2026-09-14:
 *
 *  - every product has a name of its own, separate from its description
 *  - a product can sit in several categories
 *  - the shades of one paint line are ONE product, not one row per colour
 *  - a pickup cannot be paid in cash, and a delivery cannot be paid on pickup
 */
class ProductCatalogShapeTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::create(['brand_name' => 'Testbrand']);
    }

    private function customer(): User
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'customer@example.test',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'phone' => null,   // keeps SmsService out of the checkout path
        ]);
    }

    /** One paint line stocked in three shades, each in two sizes. */
    private function multiColorProduct(): Product
    {
        $product = Product::create([
            'brand_id' => $this->brand->id,
            'name' => 'Testbrand Latex Colors',
            'description' => 'Water-based latex for interior masonry.',
        ]);

        $shades = [
            ['B-1408', 'Burnt Sienna', '#8A3324'],
            ['B-1500', 'Mandalay Road', '#C9A227'],
            ['',       'White',         '#FFFFFF'],
        ];

        foreach ($shades as [$code, $name, $hex]) {
            foreach (['1L' => 400, '4L' => 1400] as $size => $price) {
                $product->variants()->create([
                    'color_code' => $code,
                    'color_name' => $name,
                    'hex_code' => $hex,
                    'size_volume' => $size,
                    'price' => $price,
                    'stock' => 10,
                    'low_stock_threshold' => 2,
                ]);
            }
        }

        return $product->fresh(['variants']);
    }

    // -------------------------------------------------------
    // One product per line, not per colour
    // -------------------------------------------------------

    public function test_a_paint_line_stocked_in_several_shades_is_one_catalogue_row(): void
    {
        $product = $this->multiColorProduct();
        $product->categories()->attach(Category::create(['category_name' => 'Latex'])->id);

        $response = $this->getJson('/api/products')->assertOk();

        $this->assertCount(1, $response->json('data'), 'Each shade must not get its own product row.');
        $this->assertSame('Testbrand Latex Colors', $response->json('data.0.name'));
    }

    public function test_the_shades_come_along_grouped_the_way_sizes_do(): void
    {
        $product = $this->multiColorProduct();

        $colors = $this->getJson("/api/products/{$product->id}")->assertOk()->json('colors');

        $this->assertCount(3, $colors);
        $this->assertSame(
            ['Burnt Sienna (B-1408)', 'Mandalay Road (B-1500)', 'White'],
            array_column($colors, 'label'),
            'A shade with no manufacturer code is labelled by name alone.',
        );

        // Both sizes of every shade are on the wire, so the app can offer the
        // sizes that exist FOR THE CHOSEN COLOUR rather than for the product.
        $this->assertCount(6, $this->getJson("/api/products/{$product->id}")->json('active_variants'));
    }

    /**
     * The card prints "1L, 4L" — the sizes the line is sold in, once each.
     * Before this, the list was plucked per variant, so three shades × two
     * sizes read "1L, 4L, 1L, 4L, 1L, 4L".
     */
    public function test_the_size_list_names_each_size_once_however_many_shades(): void
    {
        $product = $this->multiColorProduct();

        $this->assertSame('1L, 4L', $product->size_volume);
        $this->assertSame(
            '1L, 4L',
            $this->getJson("/api/products/{$product->id}")->assertOk()->json('size_volume'),
        );
    }

    /** "4 l" is the same can as "4L"; the first spelling is the one shown. */
    public function test_a_size_spelled_differently_is_not_listed_twice(): void
    {
        $product = Product::create(['brand_id' => $this->brand->id, 'name' => 'Testbrand Enamel']);

        foreach ([['Red', '4L'], ['Blue', '4 l']] as [$name, $size]) {
            $product->variants()->create([
                'color_name' => $name,
                'size_volume' => $size,
                'price' => 900,
                'stock' => 5,
            ]);
        }

        $this->assertSame('4L', $product->fresh('variants')->size_volume);
    }

    /**
     * Two shades that both lack a manufacturer code are still two shades —
     * this is why the colour's identity is the (code, name) PAIR and not the
     * code alone.
     */
    public function test_two_uncoded_shades_do_not_collapse_into_one(): void
    {
        $product = Product::create(['brand_id' => $this->brand->id, 'name' => 'Testbrand Enamel']);

        foreach (['White', 'Off-White'] as $name) {
            $product->variants()->create([
                'color_name' => $name,
                'size_volume' => '4L',
                'price' => 900,
                'stock' => 5,
            ]);
        }

        $this->assertCount(2, $product->fresh('variants')->colors);
    }

    public function test_a_search_for_a_shade_finds_the_product_that_stocks_it(): void
    {
        $this->multiColorProduct();

        // Pasted from a shade card with a Unicode non-breaking hyphen — the
        // normalizer is what makes this match the stored ASCII form.
        $response = $this->getJson('/api/products?search='.urlencode("B\u{2011}1408"))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Testbrand Latex Colors', $response->json('data.0.name'));
    }

    // -------------------------------------------------------
    // Several categories per product
    // -------------------------------------------------------

    public function test_a_product_is_found_under_every_category_it_belongs_to(): void
    {
        $enamel = Category::create(['category_name' => 'Enamel Paint']);
        $wood = Category::create(['category_name' => 'Wood Coating']);

        $product = $this->multiColorProduct();
        $product->categories()->attach([$enamel->id, $wood->id]);

        foreach ([$enamel, $wood] as $category) {
            $response = $this->getJson("/api/products?category_id={$category->id}")->assertOk();

            $this->assertCount(1, $response->json('data'),
                "The product is missing from {$category->category_name}.");
        }
    }

    public function test_the_brand_category_chips_count_a_shared_product_under_each_heading(): void
    {
        $enamel = Category::create(['category_name' => 'Enamel Paint']);
        $wood = Category::create(['category_name' => 'Wood Coating']);

        $this->multiColorProduct()->categories()->attach([$enamel->id, $wood->id]);

        $chips = $this->getJson("/api/brands/{$this->brand->id}/categories")->assertOk()->json();

        $this->assertSame(['Enamel Paint', 'Wood Coating'], array_column($chips, 'category_name'));
        $this->assertSame([1, 1], array_column($chips, 'products_count'));
    }

    // -------------------------------------------------------
    // Payment method must fit the order type
    // -------------------------------------------------------

    private function placeOrder(string $orderType, string $paymentMethod)
    {
        $customer = $this->customer();
        $product = $this->multiColorProduct();

        $this->actingAs($customer, 'sanctum')->postJson('/api/cart/add', [
            'product_variant_id' => $product->variants->first()->id,
            'quantity' => 1,
        ])->assertOk();

        return $this->actingAs($customer, 'sanctum')->postJson('/api/orders', [
            'order_type' => $orderType,
            'payment_method' => $paymentMethod,
            'shipping_address' => $orderType === 'delivery' ? '1 Test St, Balanga' : null,
        ]);
    }

    public function test_a_pickup_cannot_be_paid_in_cash_at_the_counter(): void
    {
        // Removing this is what stops free-to-place pickup orders that nobody
        // collects while their stock sits reserved.
        $this->placeOrder('pickup', 'cash')
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_a_pickup_cannot_be_paid_cash_on_delivery(): void
    {
        $this->placeOrder('pickup', 'cod')
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_a_pickup_can_be_paid_online(): void
    {
        $this->placeOrder('pickup', 'gcash')->assertStatus(201);
    }

    public function test_a_delivery_can_be_paid_on_delivery_or_online(): void
    {
        $this->placeOrder('delivery', 'cod')->assertStatus(201);
    }

    public function test_a_delivery_cannot_be_paid_cash_at_the_counter(): void
    {
        $this->placeOrder('delivery', 'cash')
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    /** The app hides the wrong rows; this is what makes hiding them enough. */
    public function test_the_allowed_methods_are_the_ones_the_order_model_publishes(): void
    {
        $this->assertSame(['cod', 'gcash', 'card'], Order::PAYMENT_METHODS_BY_TYPE['delivery']);
        $this->assertSame(['gcash', 'card'], Order::PAYMENT_METHODS_BY_TYPE['pickup']);
    }

    // -------------------------------------------------------
    // The colour is snapshotted onto the order line
    // -------------------------------------------------------

    public function test_an_order_line_keeps_the_shade_it_was_bought_in(): void
    {
        $this->placeOrder('pickup', 'gcash')->assertStatus(201);

        $line = OrderItem::firstOrFail();

        $this->assertSame('B-1408', $line->color_code);
        $this->assertSame('Burnt Sienna', $line->color_name);
        $this->assertSame('#8A3324', $line->hex_code);

        // Recolouring the variant afterwards must not rewrite the order.
        $line->variant->update(['color_name' => 'Renamed', 'hex_code' => '#000000']);

        $this->assertSame('Burnt Sienna', $line->fresh()->color_name);
        $this->assertSame('Burnt Sienna (B-1408)', $line->fresh()->color_label);
    }
}
