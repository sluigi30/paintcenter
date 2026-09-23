<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\DeliveryProofService;
use App\Services\DeliveryService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The photo a driver takes at handover: the rule, the gate, and the clock.
 *
 * The rule is the easy half. The half worth testing hardest is that an order
 * can never reach `completed` WITHOUT one — including when the photo is
 * written and something later refuses — because that is the exact state the
 * feature exists to prevent, and it fails silently.
 */
class DeliveryProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DeliveryProofService::disk());
    }

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
        ]);
    }

    private function order(?User $driver, string $status = 'shipped', string $method = 'gcash'): Order
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
    // The rule
    // -------------------------------------------------------

    public function test_a_delivery_without_a_photo_is_refused(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver);

        $this->actingAs($driver);

        try {
            DeliveryService::deliver($order, $driver, false, null);
            $this->fail('A delivery completed with no proof photo.');
        } catch (DomainException) {
            $this->assertSame('shipped', $order->fresh()->status);
        }
    }

    public function test_a_delivery_with_a_photo_stores_it_and_completes(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver);

        $this->actingAs($driver);
        DeliveryService::deliver($order, $driver, false, $this->photo());

        $fresh = $order->fresh();

        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->proof_path);
        $this->assertNotNull($fresh->proof_captured_at);
        Storage::disk($fresh->proof_disk)->assertExists($fresh->proof_path);
    }

    public function test_a_completed_order_always_carries_proof_metadata(): void
    {
        // The invariant, stated directly: nothing this service completes can
        // have a null proof_path. Attaching the photo BEFORE advancing the
        // status is what buys it.
        $driver = $this->user('driver');
        $order  = $this->order($driver);

        $this->actingAs($driver);
        DeliveryService::deliver($order, $driver, false, $this->photo());

        $this->assertSame(0, Order::where('status', 'completed')
            ->whereNull('proof_path')
            ->count());
    }

    public function test_a_refused_cod_delivery_leaves_no_photo_behind(): void
    {
        // The file is written before the COD rule is re-checked inside the
        // service, so a refusal must take the file back down AND leave nothing
        // attached — otherwise a shipped order carries a photo of a handover
        // that never happened.
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped', 'cod');

        $this->actingAs($driver);

        try {
            DeliveryService::deliver($order, $driver, false, $this->photo());
            $this->fail('A COD delivery completed without the cash confirmed.');
        } catch (DomainException) {
            $this->assertNull($order->fresh()->proof_path);
            $this->assertEmpty(Storage::disk(DeliveryProofService::disk())->files('delivery-proofs'));
        }
    }

    // -------------------------------------------------------
    // The gate
    // -------------------------------------------------------

    private function delivered(User $driver): Order
    {
        $order = $this->order($driver);

        $this->actingAs($driver);
        DeliveryService::deliver($order, $driver, false, $this->photo());

        return $order->fresh();
    }

    public function test_the_customer_may_see_the_proof_of_their_own_delivery(): void
    {
        $driver = $this->user('driver');
        $order  = $this->delivered($driver);

        Sanctum::actingAs($order->user);

        $this->get("/api/orders/{$order->id}/proof")->assertOk();
    }

    public function test_an_admin_may_see_any_proof(): void
    {
        $driver = $this->user('driver');
        $order  = $this->delivered($driver);

        Sanctum::actingAs($this->user('admin'));

        $this->get("/api/orders/{$order->id}/proof")->assertOk();
    }

    public function test_the_carrying_driver_keeps_access_but_another_driver_does_not(): void
    {
        $driver = $this->user('driver');
        $order  = $this->delivered($driver);

        Sanctum::actingAs($driver);
        $this->get("/api/orders/{$order->id}/proof")->assertOk();

        Sanctum::actingAs($this->user('driver'));
        $this->get("/api/orders/{$order->id}/proof")->assertNotFound();
    }

    public function test_another_customer_gets_404_not_403(): void
    {
        // Order ids are a plain auto-increment, and "order 812 has a delivery
        // photo" is itself something we never agreed to tell anybody.
        $driver = $this->user('driver');
        $order  = $this->delivered($driver);

        Sanctum::actingAs($this->user('customer'));

        $this->get("/api/orders/{$order->id}/proof")->assertNotFound();
    }

    public function test_an_order_with_no_photo_is_404_rather_than_an_error(): void
    {
        $order = $this->order($this->user('driver'));

        Sanctum::actingAs($order->user);

        $this->get("/api/orders/{$order->id}/proof")->assertNotFound();
    }

    public function test_the_storage_path_never_reaches_the_customer(): void
    {
        $driver = $this->user('driver');
        $order  = $this->delivered($driver);

        Sanctum::actingAs($order->user);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            // The route, which goes through the gate — never disk and path,
            // which would be a way around it.
            ->assertJsonPath('proof_url', route('api.orders.proof', ['order' => $order->id]))
            ->assertJsonMissingPath('proof_disk')
            ->assertJsonMissingPath('proof_path');
    }

    // -------------------------------------------------------
    // The clock
    // -------------------------------------------------------

    public function test_the_prune_deletes_old_photos_but_remembers_one_was_taken(): void
    {
        $driver = $this->user('driver');
        $order  = $this->delivered($driver);
        $path   = $order->proof_path;

        // Older than the retention window.
        $order->update(['proof_captured_at' => now()->subMonths(DeliveryProofService::RETENTION_MONTHS + 1)]);

        $this->artisan('deliveries:prune-delivery-proofs')->assertSuccessful();

        $fresh = $order->fresh();

        Storage::disk(DeliveryProofService::disk())->assertMissing($path);
        $this->assertNull($fresh->proof_path);
        // Kept: the order reads as "photographed, since deleted" rather than
        // as a delivery nobody ever photographed.
        $this->assertNotNull($fresh->proof_captured_at);
    }

    public function test_the_prune_leaves_recent_photos_alone(): void
    {
        $driver = $this->user('driver');
        $order  = $this->delivered($driver);

        $this->artisan('deliveries:prune-delivery-proofs')->assertSuccessful();

        $this->assertNotNull($order->fresh()->proof_path);
        Storage::disk(DeliveryProofService::disk())->assertExists($order->proof_path);
    }

    public function test_the_prune_sweeps_a_file_no_order_points_at(): void
    {
        // DeliveryService writes the file before the row on purpose, so a fatal
        // between the two leaves exactly this. No catch block runs on a fatal,
        // which is why the sweeper is the actual guarantee.
        $disk = Storage::disk(DeliveryProofService::disk());
        $disk->put('delivery-proofs/orphan.jpg', 'not referenced by anything');

        $this->artisan('deliveries:prune-delivery-proofs')->assertSuccessful();

        $disk->assertMissing('delivery-proofs/orphan.jpg');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $disk = Storage::disk(DeliveryProofService::disk());
        $disk->put('delivery-proofs/orphan.jpg', 'x');

        $this->artisan('deliveries:prune-delivery-proofs --dry-run')->assertSuccessful();

        $disk->assertExists('delivery-proofs/orphan.jpg');
    }
}
