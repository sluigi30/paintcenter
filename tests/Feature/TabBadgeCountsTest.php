<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Message;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The counts behind the app's tab badges.
 *
 * They matter more than they look: SMS is not wired up, so order updates reach
 * the customer only through the message thread, and nothing in the app told
 * them there was anything in it.
 */
class TabBadgeCountsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Store',
            'last_name'  => 'Admin',
            'email'      => 'admin@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'admin',
        ]);
    }

    private function customer(string $email = 'customer@example.test'): User
    {
        return User::create([
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'email'      => $email,
            'password'   => bcrypt('password'),
            'role'       => 'customer',
        ]);
    }

    private function variant(): ProductVariant
    {
        $product = Product::create([
            'brand_id' => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name'     => 'Testbrand Enamel',
        ]);

        return $product->variants()->create([
            'color_name'  => 'White',
            'size_volume' => '4L',
            'price'       => 1400,
            'stock'       => 10,
        ]);
    }

    private function messageTo(User $customer, User $admin): void
    {
        Message::create([
            'sender_id'   => $admin->id,
            'receiver_id' => $customer->id,
            'content'     => 'Your order is ready for pickup.',
            'timestamp'   => now(),
            'is_read'     => false,
        ]);
    }

    public function test_a_guest_cannot_read_the_counts(): void
    {
        $this->getJson('/api/badges')->assertUnauthorized();
    }

    public function test_a_quiet_account_shows_nothing(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/badges')
            ->assertOk()
            ->assertJson(['unread_messages' => 0, 'cart_items' => 0]);
    }

    public function test_it_counts_unread_messages_from_the_store(): void
    {
        $customer = $this->customer();
        $admin    = $this->admin();

        $this->messageTo($customer, $admin);
        $this->messageTo($customer, $admin);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/badges')
            ->assertOk()
            ->assertJson(['unread_messages' => 2]);
    }

    /** The customer's own messages are not news to them. */
    public function test_it_does_not_count_the_customers_own_messages(): void
    {
        $customer = $this->customer();
        $admin    = $this->admin();

        Message::create([
            'sender_id'   => $customer->id,
            'receiver_id' => $admin->id,
            'content'     => 'Is this in stock?',
            'timestamp'   => now(),
            'is_read'     => false,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/badges')
            ->assertOk()
            ->assertJson(['unread_messages' => 0]);
    }

    /**
     * The badge and the mark-read in MessageController::thread() must agree.
     * If the two predicates drift, the badge either never clears or clears
     * before the customer has seen anything.
     */
    public function test_opening_the_thread_clears_the_badge(): void
    {
        $customer = $this->customer();
        $admin    = $this->admin();

        $this->messageTo($customer, $admin);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/badges')
            ->assertJson(['unread_messages' => 1]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/messages/thread/{$admin->id}")
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/badges')
            ->assertJson(['unread_messages' => 0]);
    }

    /** Quantity, not lines — the badge has to agree with the cart screen. */
    public function test_it_counts_cart_quantity_not_lines(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer, 'sanctum')->postJson('/api/cart/add', [
            'product_variant_id' => $this->variant()->id,
            'quantity'           => 3,
        ])->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/badges')
            ->assertOk()
            ->assertJson(['cart_items' => 3]);
    }

    public function test_one_customer_never_sees_anothers_counts(): void
    {
        $admin = $this->admin();
        $mine  = $this->customer('mine@example.test');
        $other = $this->customer('other@example.test');

        $this->messageTo($other, $admin);

        $this->actingAs($other, 'sanctum')->postJson('/api/cart/add', [
            'product_variant_id' => $this->variant()->id,
            'quantity'           => 2,
        ])->assertOk();

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/badges')
            ->assertOk()
            ->assertJson(['unread_messages' => 0, 'cart_items' => 0]);
    }
}
