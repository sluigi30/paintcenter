<?php

namespace App\Services\Reports\Metrics;

use App\Models\Order;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * What got cancelled, by whom, and why.
 *
 * Both cancel paths already require a reason and record who pressed the button
 * (OrderCancellationService), and nothing has ever read those columns back.
 *
 * Cancellations are counted against the period the order was PLACED in, like
 * every other figure — not when it was cancelled. An order placed in March and
 * cancelled in April is March's cancellation, or March's revenue and March's
 * cancellation rate would describe different sets of orders.
 *
 * Reasons are free text by design (both lists are presets, never constraints),
 * so they are grouped as stored and the long tail lands under its own label.
 */
class CancellationMetrics extends Metric
{
    protected array $deltaKeys = ['cancelled_orders', 'cancellation_rate', 'value_lost'];

    public static function key(): string
    {
        return 'cancellations';
    }

    protected function compute(ReportPeriod $period): array
    {
        $cancelled = ReportMeasure::allOrders($period)
            ->where('status', ReportMeasure::EXCLUDED_STATUS)
            ->get(['id', 'user_id', 'cancelled_by', 'cancellation_reason', 'total_amount']);

        $totalOrders = ReportMeasure::allOrders($period)->count();

        $byReason = [];
        $byWhom = ['customer' => 0, 'store' => 0, 'unrecorded' => 0];
        $valueLost = 0.0;

        foreach ($cancelled as $order) {
            $reason = trim((string) $order->cancellation_reason) ?: 'No reason recorded';

            $byReason[$reason] ??= ['reason' => $reason, 'count' => 0, 'value' => 0.0];
            $byReason[$reason]['count']++;
            $byReason[$reason]['value'] += (float) $order->total_amount;

            $byWhom[$this->whoCancelled($order)]++;
            $valueLost += (float) $order->total_amount;
        }

        usort($byReason, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'cancelled_orders' => $cancelled->count(),
            'total_orders' => $totalOrders,
            'cancellation_rate' => $totalOrders > 0
                ? round(($cancelled->count() / $totalOrders) * 100, 1)
                : 0.0,
            'value_lost' => round($valueLost, 2),
            'by_reason' => array_map(
                fn ($row) => ['value' => round($row['value'], 2)] + $row,
                array_slice($byReason, 0, $this->topN(8)),
            ),
            'by_customer' => $byWhom['customer'],
            'by_store' => $byWhom['store'],
            'by_unrecorded' => $byWhom['unrecorded'],
        ];
    }

    /**
     * Mirrors Order::cancelledByCustomer() rather than re-deciding the rule:
     * the canceller is the customer when the recorded id is the order's own
     * owner, and the store otherwise.
     */
    private function whoCancelled(Order $order): string
    {
        if ($order->cancelled_by === null) {
            return 'unrecorded';
        }

        return $order->cancelledByCustomer() ? 'customer' : 'store';
    }
}
