<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The delivery pin.
 *
 * A map search is only as good as the string it is given — a real street
 * address still resolved to the wrong part of the barangay, because
 * "Brgy. Poblacion, Pilar" is a polygon and not a doorstep. Coordinates fix
 * that, and the driver's Navigate link targets them instead.
 *
 * Everything asserted here is about the pin being OPTIONAL and never
 * destructive. It is optional because a customer may decline the permission,
 * and because somebody ordering for a job site is RIGHT to decline — their own
 * location is not the delivery's.
 */
class DeliveryLocationPinTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'email'      => 'c' . uniqid() . '@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'customer',
            'phone'      => null,
        ], $attributes));
    }

    private function readyToCheckout(User $customer): void
    {
        $brand   = Brand::create(['brand_name' => 'BOYSEN']);
        $product = Product::create([
            'name' => 'Latex White', 'description' => 'Test', 'brand_id' => $brand->id,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'size_volume' => '4L',
            'price' => 1000, 'stock' => 10, 'is_active' => true,
        ]);

        CartItem::create([
            'user_id'            => $customer->id,
            'product_id'         => $product->id,
            'product_variant_id' => $variant->id,
            'quantity'           => 1,
        ]);
    }

    private function place(array $overrides = []): array
    {
        return array_merge([
            'order_type'       => 'delivery',
            'shipping_address' => '123 Mabini St., Brgy. Poblacion, Pilar, Bataan',
            'payment_method'   => 'cod',
        ], $overrides);
    }

    public function test_a_pin_is_stored_and_stamped(): void
    {
        $customer = $this->customer();
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', $this->place([
            'delivery_lat'      => 14.6742,
            'delivery_lng'      => 120.5681,
            'location_accuracy' => 12,
        ]))->assertCreated();

        $order = Order::latest('id')->first();

        $this->assertTrue($order->hasLocationPin());
        $this->assertSame(12, $order->location_accuracy);
        // Stamped server-side. When a pin was taken is the store's record of
        // it, not something the client gets to assert.
        $this->assertNotNull($order->location_pinned_at);
    }

    public function test_an_order_without_a_pin_is_perfectly_valid(): void
    {
        // The permission may be declined, and someone ordering for a job site
        // SHOULD decline. Neither may cost them the order.
        $customer = $this->customer();
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', $this->place())->assertCreated();

        $this->assertFalse(Order::latest('id')->first()->hasLocationPin());
    }

    public function test_half_a_coordinate_is_rejected(): void
    {
        // A lost field would otherwise be stored as a pin somewhere on the
        // equator, and the driver would be sent to it.
        $customer = $this->customer();
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', $this->place(['delivery_lat' => 14.6742]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_lng');
    }

    public function test_impossible_coordinates_are_rejected(): void
    {
        $customer = $this->customer();
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', $this->place([
            'delivery_lat' => 200,
            'delivery_lng' => 400,
        ]))->assertStatus(422)
          ->assertJsonValidationErrors(['delivery_lat', 'delivery_lng']);
    }

    public function test_the_pin_is_remembered_for_next_time(): void
    {
        $customer = $this->customer();
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', $this->place([
            'delivery_lat'      => 14.6742,
            'delivery_lng'      => 120.5681,
            'location_accuracy' => 12,
        ]))->assertCreated();

        $fresh = $customer->fresh();

        $this->assertEquals(14.6742, (float) $fresh->delivery_lat);
        $this->assertEquals(120.5681, (float) $fresh->delivery_lng);
        $this->assertNotNull($fresh->location_pinned_at);
    }

    public function test_ordering_without_a_pin_does_not_wipe_a_saved_one(): void
    {
        // The case this exists for: a customer who pinned their home last month
        // orders from work this month and rightly skips the pin. Overwriting
        // with null would throw away the good one.
        $customer = $this->customer([
            'delivery_lat'       => 14.6742,
            'delivery_lng'       => 120.5681,
            'location_accuracy'  => 12,
            'location_pinned_at' => now()->subMonth(),
        ]);
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', $this->place())->assertCreated();

        $this->assertEquals(14.6742, (float) $customer->fresh()->delivery_lat);
    }

    public function test_the_driver_is_given_the_pin_and_its_accuracy(): void
    {
        $customer = $this->customer();
        $driver   = User::create([
            'first_name' => 'Mark', 'last_name' => 'Driver',
            'email' => 'd' . uniqid() . '@example.test',
            'password' => bcrypt('password'), 'role' => 'driver',
        ]);

        $order = Order::create([
            'user_id'           => $customer->id,
            'order_date'        => now(),
            'order_type'        => 'delivery',
            'status'            => 'processing',
            'total_amount'      => 1000,
            'driver_id'         => $driver->id,
            'shipping_address'  => 'Pilar, Bataan',
            'delivery_lat'      => 14.6742,
            'delivery_lng'      => 120.5681,
            'location_accuracy' => 12,
        ]);

        Sanctum::actingAs($driver);

        $this->getJson("/api/driver/deliveries/{$order->id}")
            ->assertOk()
            ->assertJsonPath('lat', 14.6742)
            ->assertJsonPath('lng', 120.5681)
            // Travels WITH the coordinates, so a ±480 m fix is never drawn as
            // a doorstep.
            ->assertJsonPath('accuracy_m', 12);
    }

    public function test_an_unpinned_delivery_hands_the_driver_nulls_not_zeroes(): void
    {
        // 0,0 is a real place in the Gulf of Guinea. A driver app that treats
        // a missing pin as the origin would draw a Navigate button to it.
        $driver = User::create([
            'first_name' => 'Mark', 'last_name' => 'Driver',
            'email' => 'd' . uniqid() . '@example.test',
            'password' => bcrypt('password'), 'role' => 'driver',
        ]);

        $order = Order::create([
            'user_id'          => $this->customer()->id,
            'order_date'       => now(),
            'order_type'       => 'delivery',
            'status'           => 'processing',
            'total_amount'     => 1000,
            'driver_id'        => $driver->id,
            'shipping_address' => 'Pilar, Bataan',
        ]);

        Sanctum::actingAs($driver);

        $this->getJson("/api/driver/deliveries/{$order->id}")
            ->assertOk()
            ->assertJsonPath('lat', null)
            ->assertJsonPath('lng', null);
    }
}
