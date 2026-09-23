<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\DeliveryProofService;
use App\Services\DeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The driver API — the endpoints a driver mobile app would live on.
 *
 * These assert the SAME rules the /driver Filament panel is tested for, on
 * purpose: both go through DeliveryService, and the value of that is only real
 * if it is checked from both sides. A rule that held in the panel and not over
 * HTTP would be a rule that quietly depended on Filament.
 */
class DriverApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DeliveryProofService::disk());
    }

    /** Every handover needs one, so every test that delivers needs one. */
    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('handover.jpg', 800, 600);
    }

    private function user(string $role): User
    {
        return User::create([
            'first_name' => ucfirst($role),
            'last_name'  => 'Person',
            'email'      => $role . uniqid() . '@example.test',
            'password'   => bcrypt('password'),
            'role'       => $role,
            'phone'      => '09171234567',
        ]);
    }

    private function order(?User $driver, string $status = 'processing', string $method = 'gcash'): Order
    {
        $order = Order::create([
            'user_id'      => $this->user('customer')->id,
            'order_date'   => now(),
            'order_type'   => 'delivery',
            'status'       => $status,
            'total_amount' => 4200,
            'driver_id'    => $driver?->id,
            'picked_up_at' => $status === 'shipped' ? now() : null,
        ]);

        Payment::create([
            'order_id'       => $order->id,
            'payment_method' => $method,
            'payment_status' => 'pending',
        ]);

        return $order->refresh();
    }

    // -------------------------------------------------------
    // The gate
    // -------------------------------------------------------

    public function test_a_guest_gets_nothing(): void
    {
        $this->getJson('/api/driver/deliveries')->assertUnauthorized();
    }

    public function test_a_customer_token_is_refused(): void
    {
        Sanctum::actingAs($this->user('customer'));

        $this->getJson('/api/driver/deliveries')->assertForbidden();
    }

    public function test_an_admin_token_is_refused_too(): void
    {
        // An admin is not delivery staff. They intervene from the panel, where
        // the override actions live.
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/driver/deliveries')->assertForbidden();
    }

    public function test_an_archived_driver_is_refused(): void
    {
        $driver = $this->user('driver');
        $driver->update(['is_archived' => true]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/deliveries')->assertForbidden();
    }

    // -------------------------------------------------------
    // Scoping
    // -------------------------------------------------------

    public function test_a_driver_lists_only_their_own_work(): void
    {
        $mine  = $this->user('driver');
        $other = $this->user('driver');

        $own = $this->order($mine);
        $this->order($other);

        Sanctum::actingAs($mine);

        $this->getJson('/api/driver/deliveries?tab=to_pick_up')
            ->assertOk()
            ->assertJsonCount(1, 'deliveries')
            ->assertJsonPath('deliveries.0.id', $own->id);
    }

    public function test_another_drivers_order_is_404_not_403(): void
    {
        // Order ids are a plain auto-increment, so a 403 on a row that exists
        // is a difference anyone can measure by counting.
        $mine   = $this->user('driver');
        $theirs = $this->order($this->user('driver'));

        Sanctum::actingAs($mine);

        $this->getJson("/api/driver/deliveries/{$theirs->id}")->assertNotFound();
    }

    public function test_a_pickup_is_never_listed(): void
    {
        $driver = $this->user('driver');
        $this->order($driver)->update(['order_type' => 'pickup']);

        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/deliveries')
            ->assertOk()
            ->assertJsonCount(0, 'deliveries');
    }

    public function test_the_detail_carries_what_the_doorstep_needs_and_no_more(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver);

        Sanctum::actingAs($driver);

        $response = $this->getJson("/api/driver/deliveries/{$order->id}")->assertOk();

        $response->assertJsonStructure([
            'customer', 'customer_phone', 'address', 'total', 'is_cod',
            'can_pick_up', 'can_deliver', 'can_report_fail', 'items',
        ]);

        // Shaped, not dumped: the customer's email is not the driver's business.
        $response->assertJsonMissingPath('user');
        $response->assertJsonMissingPath('assigned_by');
    }

    // -------------------------------------------------------
    // The delivery leg
    // -------------------------------------------------------

    public function test_a_driver_walks_an_order_to_completion(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver);

        Sanctum::actingAs($driver);

        $this->postJson("/api/driver/deliveries/{$order->id}/pick-up")
            ->assertOk()
            ->assertJsonPath('delivery.status', 'shipped');

        // Multipart, not JSON — the handover photo rides along with the action
        // so an order can never be completed with the proof still to come.
        $this->post("/api/driver/deliveries/{$order->id}/deliver", ['proof' => $this->photo()])
            ->assertOk()
            ->assertJsonPath('delivery.status', 'completed');

        $this->assertNotNull($order->fresh()->delivered_at);
        $this->assertNotNull($order->fresh()->proof_path);
    }

    public function test_picking_up_an_order_that_is_not_ready_is_refused(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped');

        Sanctum::actingAs($driver);

        $this->postJson("/api/driver/deliveries/{$order->id}/pick-up")
            ->assertStatus(422);
    }

    // -------------------------------------------------------
    // COD
    // -------------------------------------------------------

    public function test_a_cod_delivery_without_the_cash_field_is_a_422(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped', 'cod');

        Sanctum::actingAs($driver);

        $this->post("/api/driver/deliveries/{$order->id}/deliver", ['proof' => $this->photo()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_collected');

        // Nothing moved — not the status, not the payment.
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame('pending', $order->payment->fresh()->payment_status);
    }

    public function test_a_cod_delivery_denying_the_cash_is_refused_by_the_service(): void
    {
        // The field present but false gets past validation and must still be
        // stopped — the rule lives in DeliveryService, not in the request.
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped', 'cod');

        Sanctum::actingAs($driver);

        $this->post("/api/driver/deliveries/{$order->id}/deliver", [
            'cash_collected' => false,
            'proof'          => $this->photo(),
        ])->assertStatus(422);

        $this->assertSame('shipped', $order->fresh()->status);
        // The photo was written before the rule refused, so it must not be
        // left attached to an order that never completed.
        $this->assertNull($order->fresh()->proof_path);
    }

    public function test_confirming_the_cash_completes_and_marks_it_paid(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped', 'cod');

        Sanctum::actingAs($driver);

        $this->post("/api/driver/deliveries/{$order->id}/deliver", [
            'cash_collected' => true,
            'proof'          => $this->photo(),
        ])->assertOk()->assertJsonPath('delivery.status', 'completed');

        $this->assertSame('paid', $order->payment->fresh()->payment_status);
        $this->assertNotNull($order->fresh()->cash_collected_at);
    }

    // -------------------------------------------------------
    // Failed attempts
    // -------------------------------------------------------

    public function test_a_failed_attempt_needs_a_reason(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped');

        Sanctum::actingAs($driver);

        $this->postJson("/api/driver/deliveries/{$order->id}/fail")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_failed_attempt_leaves_the_status_alone(): void
    {
        $this->user('admin'); // someone for the store to speak through
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped');

        Sanctum::actingAs($driver);

        $this->postJson("/api/driver/deliveries/{$order->id}/fail", ['reason' => 'Nobody home'])
            ->assertOk()
            ->assertJsonPath('delivery.status', 'shipped')
            ->assertJsonPath('delivery.attempts', 1);

        // The goods DID leave the store; that fact is not erased.
        $this->assertNotNull($order->fresh()->picked_up_at);
    }

    public function test_the_cap_closes_the_action_over_http_too(): void
    {
        $this->user('admin');
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped');

        Sanctum::actingAs($driver);

        for ($i = 0; $i < DeliveryService::MAX_ATTEMPTS; $i++) {
            $this->postJson("/api/driver/deliveries/{$order->id}/fail", ['reason' => 'Nobody home'])
                ->assertOk();
        }

        $this->postJson("/api/driver/deliveries/{$order->id}/fail", ['reason' => 'Nobody home'])
            ->assertStatus(422);

        $this->getJson("/api/driver/deliveries/{$order->id}")
            ->assertJsonPath('can_report_fail', false);
    }

    // -------------------------------------------------------
    // Shared vocabulary
    // -------------------------------------------------------

    public function test_the_app_is_handed_the_same_reason_list_the_panel_uses(): void
    {
        // So the two cannot drift into offering different words for the same
        // thing — the mistake STATUS_FLOW_BY_TYPE and the mobile FLOW have to
        // be kept in step by hand.
        Sanctum::actingAs($this->user('driver'));

        $this->getJson('/api/driver/failure-reasons')
            ->assertOk()
            ->assertJsonPath('max_attempts', DeliveryService::MAX_ATTEMPTS)
            ->assertJsonPath('reasons', DeliveryService::FAILURE_REASONS);
    }

    public function test_the_badge_counts_are_scoped_to_this_driver(): void
    {
        $mine = $this->user('driver');
        $this->order($mine, 'processing');
        $this->order($mine, 'shipped');
        $this->order($this->user('driver'), 'processing');

        Sanctum::actingAs($mine);

        $this->getJson('/api/driver/deliveries/summary')
            ->assertOk()
            ->assertJsonPath('to_pick_up', 1)
            ->assertJsonPath('out', 1);
    }
}
