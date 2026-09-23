<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Reports\Metrics\DeliveryMetrics;
use App\Services\Reports\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Delivery performance — the answer to "did the driver role help?".
 *
 * The property most worth pinning down is the date anchor. Every revenue figure
 * in this system hangs off `orders.created_at`, so an order belongs to the
 * month it was PLACED. This report deliberately does not: a handover on 2
 * October for an order placed 28 September is October's work, by October's
 * driver. Anchoring it to created_at would credit the wrong month and, where
 * the order was reassigned, the wrong person.
 */
class DeliveryMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function driver(string $first = 'Mark'): User
    {
        return User::create([
            'first_name' => $first,
            'last_name'  => 'Driver',
            'email'      => strtolower($first) . uniqid() . '@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'driver',
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'email' => 'c' . uniqid() . '@example.test',
            'password' => bcrypt('password'), 'role' => 'customer',
        ]);
    }

    private function delivery(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'user_id'      => $this->customer()->id,
            'order_date'   => now(),
            'order_type'   => 'delivery',
            'status'       => 'completed',
            'total_amount' => 1000,
        ], $attributes));
    }

    /** September, the month everything below is measured in. */
    private function period(): ReportPeriod
    {
        return ReportPeriod::make(
            now()->setDate(2026, 9, 1)->startOfDay(),
            now()->setDate(2026, 9, 30)->endOfDay(),
        );
    }

    private function metrics(): array
    {
        return (new DeliveryMetrics($this->period(), []))->get()['current'];
    }

    public function test_it_counts_by_when_the_delivery_happened_not_when_it_was_ordered(): void
    {
        $driver = $this->driver();

        // Ordered in August, delivered in September — September's work.
        $this->delivery([
            'created_at'   => now()->setDate(2026, 8, 28),
            'driver_id'    => $driver->id,
            'picked_up_at' => now()->setDate(2026, 9, 2)->setTime(9, 0),
            'delivered_at' => now()->setDate(2026, 9, 2)->setTime(9, 45),
        ]);

        // Ordered in September, delivered in October — NOT September's work.
        $this->delivery([
            'created_at'   => now()->setDate(2026, 9, 29),
            'driver_id'    => $driver->id,
            'picked_up_at' => now()->setDate(2026, 10, 1)->setTime(9, 0),
            'delivered_at' => now()->setDate(2026, 10, 1)->setTime(9, 30),
        ]);

        $this->assertSame(1, $this->metrics()['completed']);
    }

    public function test_the_first_time_rate_counts_deliveries_that_needed_no_retry(): void
    {
        $driver = $this->driver();

        foreach ([0, 0, 0, 2] as $attempts) {
            $this->delivery([
                'driver_id'       => $driver->id,
                'failed_attempts' => $attempts,
                'picked_up_at'    => now()->setDate(2026, 9, 10)->setTime(9, 0),
                'delivered_at'    => now()->setDate(2026, 9, 10)->setTime(10, 0),
            ]);
        }

        $m = $this->metrics();

        $this->assertSame(4, $m['completed']);
        $this->assertSame(1, $m['needed_retry']);
        $this->assertSame(75.0, $m['first_time_rate']);
        $this->assertSame(2, $m['failed_attempts']);
    }

    public function test_time_on_the_road_ignores_deliveries_the_store_closed_by_hand(): void
    {
        $driver = $this->driver();

        // 60 minutes, driven.
        $this->delivery([
            'driver_id'    => $driver->id,
            'picked_up_at' => now()->setDate(2026, 9, 10)->setTime(9, 0),
            'delivered_at' => now()->setDate(2026, 9, 10)->setTime(10, 0),
        ]);

        // Advanced through the admin override: delivered, never picked up.
        // Averaging it in would drag the figure toward a journey nobody made.
        $this->delivery([
            'driver_id'    => null,
            'picked_up_at' => null,
            'delivered_at' => now()->setDate(2026, 9, 11)->setTime(10, 0),
        ]);

        $this->assertSame(60, $this->metrics()['avg_minutes']);
    }

    public function test_it_breaks_the_work_down_by_driver(): void
    {
        $mark = $this->driver('Mark');
        $ana  = $this->driver('Ana');

        foreach ([$mark, $mark, $ana] as $driver) {
            $this->delivery([
                'driver_id'         => $driver->id,
                'delivered_at'      => now()->setDate(2026, 9, 10),
                'proof_captured_at' => now()->setDate(2026, 9, 10),
            ]);
        }

        $rows = $this->metrics()['by_driver'];

        // Busiest first.
        $this->assertSame('Mark Driver', $rows[0]['driver']);
        $this->assertSame(2, $rows[0]['delivered']);
        $this->assertSame(2, $rows[0]['with_proof']);
        $this->assertSame('Ana Driver', $rows[1]['driver']);
    }

    public function test_cash_is_credited_only_when_a_driver_confirmed_collecting_it(): void
    {
        // Read from cash_collected_at, which only a driver's confirmation ever
        // stamps — never inferred from the payment status, which an admin can
        // reach by other routes.
        $driver = $this->driver();

        $this->delivery([
            'driver_id'         => $driver->id,
            'total_amount'      => 4200,
            'delivered_at'      => now()->setDate(2026, 9, 10),
            'cash_collected_at' => now()->setDate(2026, 9, 10),
        ]);

        $this->delivery([
            'driver_id'         => $driver->id,
            'total_amount'      => 9999,
            'delivered_at'      => now()->setDate(2026, 9, 11),
            'cash_collected_at' => null,   // paid online
        ]);

        $this->assertSame(4200.0, $this->metrics()['by_driver'][0]['cash_collected']);
    }

    public function test_a_delivery_with_no_driver_is_named_rather_than_dropped(): void
    {
        // Dropping the row would make the per-driver counts stop adding up to
        // the headline, which reads as a bug rather than as the override it is.
        $this->delivery([
            'driver_id'    => null,
            'delivered_at' => now()->setDate(2026, 9, 10),
        ]);

        $m = $this->metrics();

        $this->assertSame(1, $m['completed']);
        $this->assertSame('Completed by the store', $m['by_driver'][0]['driver']);
        $this->assertSame(1, $m['unassigned']);
    }

    public function test_an_empty_period_reports_nothing_rather_than_dividing_by_zero(): void
    {
        $m = $this->metrics();

        $this->assertSame(0, $m['completed']);
        $this->assertNull($m['first_time_rate']);
        $this->assertNull($m['avg_minutes']);
        $this->assertSame([], $m['by_driver']);
    }
}
