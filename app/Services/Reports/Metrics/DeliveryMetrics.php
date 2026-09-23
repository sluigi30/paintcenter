<?php

namespace App\Services\Reports\Metrics;

use App\Models\Order;
use App\Services\DeliveryService;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * How the delivery operation actually performed, and who did it.
 *
 * -- Why this is anchored to delivered_at, not created_at ------------------
 *
 * ReportMeasure::DATE_ANCHOR is `created_at`, and every revenue figure in this
 * system uses it: an order belongs to the period it was PLACED in, so the same
 * order can never be counted in two months of sales.
 *
 * This report is not about orders, it is about WORK. A delivery handed over on
 * 2 October for an order placed on 28 September is October's work, done by
 * October's driver, and attributing it to September would credit the wrong
 * month and — where drivers changed — the wrong person. So the completed and
 * failed figures here are anchored to `delivered_at` and to the attempt, and
 * this metric deliberately does NOT reconcile to the period's order count.
 * That is not a discrepancy to be fixed later; it is two different questions.
 *
 * The per-driver table is the answer to "did the driver role help?", which is
 * a question the store could not previously answer at all.
 */
class DeliveryMetrics extends Metric
{
    protected string $temporality = ReportMeasure::PERIOD;

    public static function key(): string
    {
        return 'deliveries';
    }

    protected function compute(ReportPeriod $period): array
    {
        $delivered = Order::query()
            ->where('order_type', 'delivery')
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$period->from, $period->to]);

        $completed = (clone $delivered)->count();

        // Attempts are counted on orders DELIVERED in the window, so the rate
        // below compares like with like: how many of the deliveries finished
        // this period needed more than one trip.
        $withAttempts = (clone $delivered)->where('failed_attempts', '>', 0)->count();
        $attemptTotal = (int) (clone $delivered)->sum('failed_attempts');

        // Still out and already over the limit — nobody is coming back for
        // these without an admin deciding something. Point-in-time inside a
        // period report, so it is labelled as such in the partial.
        $stuck = Order::query()
            ->where('order_type', 'delivery')
            ->where('status', 'shipped')
            ->where('failed_attempts', '>=', DeliveryService::MAX_ATTEMPTS)
            ->count();

        return [
            'completed'          => $completed,
            'first_time_rate'    => $completed > 0
                ? round((($completed - $withAttempts) / $completed) * 100, 1)
                : null,
            'needed_retry'       => $withAttempts,
            'failed_attempts'    => $attemptTotal,
            'avg_minutes'        => $this->averageMinutesOnTheRoad($period),
            'stuck_at_limit'     => $stuck,
            'max_attempts'       => DeliveryService::MAX_ATTEMPTS,
            'by_driver'          => $this->byDriver($period),
            'unassigned'         => (clone $delivered)->whereNull('driver_id')->count(),
        ];
    }

    /**
     * Pickup to handover, averaged.
     *
     * Only over deliveries that carry BOTH timestamps. An order an admin
     * advanced by hand (the override path) has a delivered_at and no
     * picked_up_at, and including it would silently drag the average toward
     * zero with a number nobody drove.
     */
    private function averageMinutesOnTheRoad(ReportPeriod $period): ?int
    {
        $rows = Order::query()
            ->where('order_type', 'delivery')
            ->whereNotNull('picked_up_at')
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$period->from, $period->to])
            ->get(['picked_up_at', 'delivered_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $minutes = $rows
            ->map(fn ($row) => $row->picked_up_at->diffInMinutes($row->delivered_at))
            // A negative span means the two timestamps were written out of
            // order by a manual correction; it describes no journey.
            ->filter(fn ($m) => $m >= 0);

        return $minutes->isEmpty() ? null : (int) round($minutes->avg());
    }

    /**
     * One row per driver who completed something in the window.
     *
     * COD cash is included because it is the figure the store most needs and
     * has no other way to see: which driver took how much money this period.
     * Counted from `cash_collected_at`, which is only ever stamped by a driver
     * confirming collection — never inferred from the payment status.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byDriver(ReportPeriod $period): array
    {
        return Order::query()
            ->leftJoin('users', 'users.id', '=', 'orders.driver_id')
            ->where('orders.order_type', 'delivery')
            ->whereNotNull('orders.delivered_at')
            ->whereBetween('orders.delivered_at', [$period->from, $period->to])
            ->groupBy('orders.driver_id', 'users.first_name', 'users.last_name')
            ->selectRaw(implode(', ', [
                'orders.driver_id',
                'users.first_name',
                'users.last_name',
                'COUNT(*) as delivered',
                'SUM(orders.failed_attempts) as failed_attempts',
                'SUM(CASE WHEN orders.cash_collected_at IS NOT NULL THEN orders.total_amount ELSE 0 END) as cash_collected',
                'SUM(CASE WHEN orders.proof_captured_at IS NOT NULL THEN 1 ELSE 0 END) as with_proof',
            ]))
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->map(fn ($row) => [
                'driver'          => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''))
                    // A delivery completed through the admin override carries
                    // no driver. Saying so is more honest than dropping the
                    // row, which would make the counts stop adding up.
                    ?: 'Completed by the store',
                'delivered'       => (int) $row->delivered,
                'failed_attempts' => (int) $row->failed_attempts,
                'cash_collected'  => round((float) $row->cash_collected, 2),
                'with_proof'      => (int) $row->with_proof,
            ])
            ->all();
    }
}
