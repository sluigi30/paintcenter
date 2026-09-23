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
 * The delivery address, and the landmark beside it.
 *
 * The landmark is a separate column rather than more text in the address
 * because the two are consumed differently: the address is handed to a map
 * search, the landmark is read by a person standing in the street. Out here it
 * is frequently the more useful of the two — the postal code for Pilar is 2101
 * and tells a driver nothing.
 *
 * The part most worth locking down is the persistence. `users.address` was
 * only ever written at registration, so checkout pre-filled a value the
 * customer had no way to improve: a better address typed here was forgotten by
 * the next order, and they retyped something short. Without this, improving
 * the PROMPT would have achieved nothing.
 */
class DeliveryAddressTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'email'      => 'customer' . uniqid() . '@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'customer',
            'phone'      => null,
        ], $attributes));
    }

    /** A customer with one in-stock can in their cart. */
    private function readyToCheckout(User $customer): void
    {
        $brand = Brand::create(['brand_name' => 'BOYSEN']);

        $product = Product::create([
            'name'        => 'Latex White',
            'description' => 'Test paint',
            'brand_id'    => $brand->id,
        ]);

        $variant = ProductVariant::create([
            'product_id'  => $product->id,
            'size_volume' => '4L',
            'price'       => 1000,
            'stock'       => 10,
            'is_active'   => true,
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
            'order_type'        => 'delivery',
            'shipping_address'  => '123 Mabini St., Brgy. Poblacion, Pilar, Bataan',
            'delivery_landmark' => 'Near ABC Store, green gate',
            'payment_method'    => 'cod',
        ], $overrides);
    }

    public function test_the_landmark_is_stored_on_the_order(): void
    {
        $customer = $this->customer();
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', $this->place())->assertCreated();

        $this->assertSame(
            'Near ABC Store, green gate',
            Order::latest('id')->first()->delivery_landmark,
        );
    }

    public function test_checkout_remembers_the_address_and_landmark(): void
    {
        // The whole reason the prompt was worth improving: what the customer
        // types becomes the default next time, so the address gets better over
        // orders instead of resetting to whatever registration captured.
        $customer = $this->customer(['address' => 'Pilar, Bataan']);
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', $this->place())->assertCreated();

        $fresh = $customer->fresh();

        $this->assertSame('123 Mabini St., Brgy. Poblacion, Pilar, Bataan', $fresh->address);
        $this->assertSame('Near ABC Store, green gate', $fresh->landmark);
    }

    public function test_a_pickup_never_overwrites_the_saved_address(): void
    {
        // A pickup carries no address, and blanking the one on file because
        // somebody collected an order at the counter would lose it.
        $customer = $this->customer(['address' => 'Pilar, Bataan', 'landmark' => 'Green gate']);
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', [
            'order_type'     => 'pickup',
            'payment_method' => 'gcash',
        ])->assertCreated();

        $fresh = $customer->fresh();

        $this->assertSame('Pilar, Bataan', $fresh->address);
        $this->assertSame('Green gate', $fresh->landmark);
    }

    public function test_the_landmark_is_optional(): void
    {
        // It is the most useful line on the form and still must not block an
        // order from a customer who cannot think of one.
        $customer = $this->customer();
        $this->readyToCheckout($customer);

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', $this->place(['delivery_landmark' => null]))
            ->assertCreated();

        $this->assertNull(Order::latest('id')->first()->delivery_landmark);
    }

    public function test_the_driver_is_given_the_landmark(): void
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
            'delivery_landmark' => 'Near ABC Store, green gate',
        ]);

        Sanctum::actingAs($driver);

        $this->getJson("/api/driver/deliveries/{$order->id}")
            ->assertOk()
            ->assertJsonPath('landmark', 'Near ABC Store, green gate');
    }
}
