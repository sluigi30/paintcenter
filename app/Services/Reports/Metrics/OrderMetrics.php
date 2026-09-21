<?php

namespace App\Services\Reports\Metrics;

use App\Models\Order;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * How the period's orders broke down: by status, and by delivery vs pickup.
 *
 * The status breakdown is the one report that reads EVERY order including
 * cancelled ones — it exists precisely to show what the countable gate removes.
 * Its revenue column is therefore not comparable with the headline figure, and
 * says so in the payload.
 */
class OrderMetrics extends Metric
{
    protected array $deltaKeys = ['total_orders', 'countable_orders'];

    public static function key(): string
    {
        return 'orders';
    }

    protected function compute(ReportPeriod $period): array
    {
        $byStatus = ReportMeasure::allOrders($period)
            ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'label' => Order::statusLabel($row->status),
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $byType = ReportMeasure::orders($period)
            ->selectRaw('order_type, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('order_type')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->order_type,
                'label' => ucfirst((string) $row->order_type),
                'count' => (int) $row->count,
                'revenue' => round((float) $row->total, 2),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();

        $total = ReportMeasure::allOrders($period)->count();

        return [
            'by_status' => $byStatus,
            'by_type' => $byType,
            'total_orders' => $total,
            'countable_orders' => ReportMeasure::orderCount($period),
            'status_note' => 'Includes cancelled orders, so this table is the only one whose totals differ from the headline revenue.',
        ];
    }
}
