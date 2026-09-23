<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the customer's app is told about who is bringing their order.
 *
 * The other half of the admin's flood is "where is my order" — messages an
 * admin cannot answer any better than the person holding the cans. Naming the
 * driver and giving a number answers it without anyone at the store typing.
 */
class CustomerSeesDriverTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::create([
            'first_name' => 'Mark',
            'last_name'  => 'Villanueva',
            'email'      => $role . uniqid() . '@example.test',
            'password'   => bcrypt('password'),
            'role'       => $role,
            'phone'      => '09171234567',
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'email'      => 'customer' . uniqid() . '@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'customer',
            'phone'      => null,
        ]);
    }

    private function order(User $customer, ?User $driver, string $status): Order
    {
        $order = Order::create([
            'user_id'      => $customer->id,
            'order_date'   => now(),
            'order_type'   => 'delivery',
            'status'       => $status,
            'total_amount' => 4200,
            'driver_id'    => $driver?->id,
            'assigned_by'  => $driver ? 1 : null,
        ]);

        Payment::create([
            'order_id'       => $order->id,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
        ]);

        return $order->refresh();
    }

    public function test_the_driver_is_named_once_the_order_is_out(): void
    {
        $customer = $this->customer();
        $driver   = $this->staff('driver');
        $order    = $this->order($customer, $driver, 'shipped');

        Sanctum::actingAs($customer);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('driver_contact.name', 'Mark Villanueva')
            ->assertJsonPath('driver_contact.phone', '09171234567');
    }

    public function test_the_driver_is_not_named_before_the_order_leaves_the_store(): void
    {
        // An assignment can still change while the order is being packed, and
        // naming a driver who is then swapped is worse than naming nobody.
        $customer = $this->customer();
        $driver   = $this->staff('driver');
        $order    = $this->order($customer, $driver, 'processing');

        Sanctum::actingAs($customer);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('driver_contact', null);
    }

    public function test_an_unassigned_delivery_names_nobody(): void
    {
        $customer = $this->customer();
        $order    = $this->order($customer, null, 'shipped');

        Sanctum::actingAs($customer);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('driver_contact', null);
    }

    public function test_operational_fields_never_reach_the_customer(): void
    {
        $customer = $this->customer();
        $driver   = $this->staff('driver');
        $order    = $this->order($customer, $driver, 'shipped');
        $order->update(['cash_collected_at' => now()]);

        Sanctum::actingAs($customer);

        $response = $this->getJson("/api/orders/{$order->id}")->assertOk();

        // Which admin assigned it, and when the driver handed cash over, are
        // the store's business.
        $response->assertJsonMissingPath('driver_id')
            ->assertJsonMissingPath('assigned_by')
            ->assertJsonMissingPath('cash_collected_at');
    }

    public function test_the_customer_still_gets_their_own_delivery_facts(): void
    {
        // These are what the app dates the tracker with and explains a missed
        // attempt from — hiding them would leave the tracker guessing again.
        $customer = $this->customer();
        $driver   = $this->staff('driver');
        $order    = $this->order($customer, $driver, 'shipped');
        $order->update([
            'picked_up_at'    => now(),
            'failed_attempts' => 1,
            'delivery_note'   => 'Nobody home',
        ]);

        Sanctum::actingAs($customer);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('failed_attempts', 1)
            ->assertJsonPath('delivery_note', 'Nobody home')
            ->assertJsonMissing(['picked_up_at' => null]);
    }

    public function test_the_orders_list_carries_the_driver_too(): void
    {
        $customer = $this->customer();
        $driver   = $this->staff('driver');
        $this->order($customer, $driver, 'shipped');

        Sanctum::actingAs($customer);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('0.driver_contact.name', 'Mark Villanueva');
    }
}
