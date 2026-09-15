<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The admin panel has to learn about a new order on its own.
 *
 * Nothing announced one before this: an admin on any screen but the orders
 * list found out by reloading. The bell already polls, so a customer checkout
 * writes a database notification to every active admin.
 */
class NewOrderAdminAlertTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email, bool $archived = false): User
    {
        return User::create([
            'first_name'  => 'Test',
            'last_name'   => 'Admin',
            'email'       => $email,
            'password'    => bcrypt('password'),
            'role'        => 'admin',
            'is_archived' => $archived,
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'email'      => 'juan@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'customer',
            'phone'      => null,   // keeps SmsService out of the checkout path
        ]);
    }

    private function product(): Product
    {
        $product = Product::create([
            'brand_id' => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name'     => 'Testbrand Enamel',
        ]);

        $product->variants()->create([
            'color_name'  => 'White',
            'size_volume' => '4L',
            'price'       => 1400,
            'stock'       => 10,
        ]);

        return $product->fresh('variants');
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function placeOrder(User $customer, int $quantity = 2)
    {
        $this->actingAs($customer, 'sanctum')->postJson('/api/cart/add', [
            'product_variant_id' => $this->product()->variants->first()->id,
            'quantity'           => $quantity,
        ])->assertOk();

        return $this->actingAs($customer, 'sanctum')->postJson('/api/orders', [
            'order_type'       => 'delivery',
            'payment_method'   => 'cod',
            'shipping_address' => '1 Test St, Balanga',
        ]);
    }

    public function test_placing_an_order_rings_every_active_admin(): void
    {
        $first  = $this->admin('one@example.test');
        $second = $this->admin('two@example.test');

        $this->placeOrder($this->customer())->assertStatus(201);

        foreach ([$first, $second] as $admin) {
            $this->assertCount(1, $admin->refresh()->notifications);
            $this->assertSame('New Order', $admin->notifications->first()->data['title']);
        }
    }

    /** An archived admin has left the store; their bell stays quiet. */
    public function test_an_archived_admin_is_not_alerted(): void
    {
        $archived = $this->admin('gone@example.test', archived: true);

        $this->placeOrder($this->customer())->assertStatus(201);

        $this->assertCount(0, $archived->refresh()->notifications);
    }

    public function test_the_customer_is_not_alerted_in_the_admin_panel(): void
    {
        $this->admin('one@example.test');
        $customer = $this->customer();

        $this->placeOrder($customer)->assertStatus(201);

        // Their announcement is the message thread, not the admin bell.
        $this->assertCount(0, $customer->refresh()->notifications);
    }

    public function test_the_alert_names_the_customer_and_the_total(): void
    {
        $admin = $this->admin('one@example.test');

        $this->placeOrder($this->customer(), quantity: 2)->assertStatus(201);

        $body = $admin->refresh()->notifications->first()->data['body'];

        $this->assertStringContainsString('Juan Dela Cruz', $body);
        $this->assertStringContainsString('2 items', $body);
        $this->assertStringContainsString('2,800.00', $body);
        $this->assertStringContainsString('delivery', $body);
    }

    /**
     * The alert is posted after the commit, so a checkout that never becomes
     * an order never rings anyone — no admin chasing an order that is not there.
     */
    public function test_a_rejected_checkout_alerts_nobody(): void
    {
        $admin    = $this->admin('one@example.test');
        $customer = $this->customer();

        $this->actingAs($customer, 'sanctum')->postJson('/api/cart/add', [
            'product_variant_id' => $this->product()->variants->first()->id,
            'quantity'           => 1,
        ])->assertOk();

        // Cash at the counter is not an accepted pickup method.
        $this->actingAs($customer, 'sanctum')->postJson('/api/orders', [
            'order_type'     => 'pickup',
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertCount(0, $admin->refresh()->notifications);
    }

    /**
     * The "View order" link must not carry the API's host into the admin's
     * browser. Orders are placed from the mobile app, which talks to the LAN
     * IP in dev and the Cloud domain in production — neither is the address
     * the admin has the panel open on. A root-relative path is what makes the
     * button land in the panel the admin is already using.
     */
    public function test_the_alert_links_relatively_not_to_the_api_host(): void
    {
        $admin = $this->admin('one@example.test');

        // Exactly what the phone does: same app, a different host.
        $this->withServerVariables(['HTTP_HOST' => '192.168.1.69:8000']);

        $this->placeOrder($this->customer())->assertStatus(201);

        $url = $admin->refresh()->notifications->first()->data['actions'][0]['url'];

        $this->assertStringNotContainsString('192.168.1.69', $url);
        $this->assertStringStartsWith('/admin/orders/', $url);
        $this->assertStringEndsWith('/edit', $url);
    }

    /**
     * The alert is posted after the commit but still inside the controller's
     * try/catch, so an exception here would answer "Order failed" for an order
     * that IS placed — and the customer would place it again. Dropping the
     * notifications table is a stand-in for any such failure.
     */
    public function test_a_failing_alert_does_not_fail_the_checkout(): void
    {
        $this->admin('one@example.test');

        Schema::drop('notifications');

        $this->placeOrder($this->customer())->assertStatus(201);

        $this->assertSame(1, Order::count(), 'The order must survive a broken bell.');
    }

    // -------------------------------------------------------
    // Sidebar badge
    // -------------------------------------------------------

    public function test_the_nav_badge_counts_only_pending_orders(): void
    {
        $this->admin('one@example.test');
        $customer = $this->customer();

        $this->assertNull(OrderResource::getNavigationBadge(), 'No orders, no badge.');

        $this->placeOrder($customer)->assertStatus(201);
        $this->assertSame('1', OrderResource::getNavigationBadge());

        Order::firstOrFail()->update(['status' => 'processing']);
        $this->assertNull(OrderResource::getNavigationBadge(), 'Work already started is not waiting.');
    }
}
