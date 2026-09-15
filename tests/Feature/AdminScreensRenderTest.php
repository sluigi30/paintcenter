<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\LowStockWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every admin screen renders with real data in it.
 *
 * Colour moved from the product onto its variants and the single category
 * became a pivot (2026-09-14), which touched the product form, the products
 * and inventory tables, the order mix sheet, the dashboard widgets and the
 * reports page. A stale `$record->hex_code` or `product.category` in any of
 * them is a 500 on a page no unit test opens — so each one is opened here,
 * with a product, an order and a payment behind it rather than against an
 * empty database where a broken accessor is never reached.
 */
class AdminScreensRenderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Test',
            'last_name'  => 'Admin',
            'email'      => 'admin@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'admin',
        ]);
    }

    /** A multi-shade product, an order that bought one of the shades, a payment. */
    private function seedStore(): Product
    {
        $product = Product::create([
            'brand_id'    => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name'        => 'Testbrand Latex Colors',
            'description' => 'Water-based latex for interior masonry.',
        ]);

        $product->categories()->attach([
            Category::create(['category_name' => 'Enamel Paint'])->id,
            Category::create(['category_name' => 'Wood Coating'])->id,
        ]);

        $sienna = $product->variants()->create([
            'color_code'          => 'B-1408',
            'color_name'          => 'Burnt Sienna',
            'hex_code'            => '#8A3324',
            'size_volume'         => '4L',
            'price'               => 1400,
            'stock'               => 3,
            'low_stock_threshold' => 10,   // low, so LowStockWidget has a row
        ]);

        // An uncoded shade, which is what an empty color_code has to survive.
        $product->variants()->create([
            'color_name'          => 'White',
            'size_volume'         => '4L',
            'price'               => 1300,
            'stock'               => 40,
            'low_stock_threshold' => 5,
        ]);

        $customer = User::create([
            'first_name' => 'Test',
            'last_name'  => 'Customer',
            'email'      => 'customer@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'customer',
        ]);

        $order = Order::create([
            'user_id'      => $customer->id,
            'order_date'   => now(),
            'order_type'   => 'pickup',
            'status'       => 'pending',
            'total_amount' => 1400,
        ]);

        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $product->id,
            'product_variant_id' => $sienna->id,
            'color_code'         => 'B-1408',
            'color_name'         => 'Burnt Sienna',
            'hex_code'           => '#8A3324',
            'size_volume'        => '4L',
            'quantity'           => 1,
            'unit_price'         => 1400,
            'subtotal'           => 1400,
        ]);

        Payment::create([
            'order_id'       => $order->id,
            'payment_method' => 'gcash',
            'payment_status' => 'pending',
        ]);

        return $product;
    }

    public static function adminScreens(): array
    {
        return [
            'dashboard'        => ['/admin/dashboard'],
            'products list'    => ['/admin/products'],
            'product create'   => ['/admin/products/create'],
            'inventory'        => ['/admin/inventories'],
            'orders list'      => ['/admin/orders'],
            'brands list'      => ['/admin/brands'],
            'brand create'     => ['/admin/brands/create'],
            'categories list'  => ['/admin/categories'],
            'category create'  => ['/admin/categories/create'],
            'reports'          => ['/admin/reports'],
            // Activity logs are super-admin only and untouched by this change.
        ];
    }

    #[DataProvider('adminScreens')]
    public function test_an_admin_screen_renders(string $url): void
    {
        $this->seedStore();

        $this->actingAs($this->admin())->get($url)->assertOk();
    }

    /**
     * Dashboard::getWidgets() REPLACES Filament's "every discovered widget"
     * default, so a widget left out of that list renders nowhere while still
     * existing, still being documented, and still passing the smoke test
     * above. LowStockWidget was in exactly that state.
     *
     * Asserted against the list rather than the HTML because table widgets are
     * lazy — the dashboard's first response carries the Livewire placeholder,
     * not the table.
     */
    public function test_the_dashboard_includes_the_low_stock_widget(): void
    {
        $this->assertContains(LowStockWidget::class, (new Dashboard)->getWidgets());
    }

    /** ...and the widget itself lists the variant that needs attention. */
    public function test_the_low_stock_widget_lists_a_low_variant(): void
    {
        $this->seedStore();   // seeds a variant at 3 units against a threshold of 10

        Livewire::actingAs($this->admin())
            ->test(LowStockWidget::class)
            ->assertOk()
            ->assertSee('Testbrand Latex Colors');
    }

    /**
     * Manage is the widget's only action now that Restock was removed, so it
     * has to land somewhere useful: Inventory with the product searched, not
     * the full list with the item the admin just clicked nowhere in sight.
     */
    public function test_the_low_stock_widget_links_to_the_products_inventory_rows(): void
    {
        $this->seedStore();

        $html = Livewire::actingAs($this->admin())
            ->test(LowStockWidget::class)
            ->html();

        $this->assertStringContainsString(
            '/admin/inventories?search=Testbrand%20Latex%20Colors',
            $html,
        );
    }

    public function test_the_product_edit_form_loads_an_existing_product(): void
    {
        $product = $this->seedStore();

        $response = $this->actingAs($this->admin())
            ->get("/admin/products/{$product->id}/edit")
            ->assertOk();

        // The name is the field QA asked for; the shades prove the variants
        // repeater is reading colour off the rows.
        $response->assertSee('Testbrand Latex Colors');
        $response->assertSee('Burnt Sienna');
    }

    public function test_the_order_edit_screen_shows_the_shade_that_was_bought(): void
    {
        $this->seedStore();

        $order = Order::firstOrFail();

        $this->actingAs($this->admin())
            ->get("/admin/orders/{$order->id}/edit")
            ->assertOk()
            ->assertSee('Burnt Sienna (B-1408)');
    }
}
