<?php

namespace App\Services\Reports\Metrics;

use App\Models\Order;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * Work outstanding at this moment: orders still open, whenever they were placed.
 *
 * POINT-IN-TIME and ignores the reporting window entirely — which is correct,
 * and is exactly why it must never be rendered as though it were a period
 * figure. The page this replaces showed a live pending count inside a report
 * headed with a past date range.
 *
 * Open means anything not yet completed or cancelled, i.e. still somewhere on
 * Order::STATUS_FLOW_BY_TYPE.
 */
class OperationsMetrics extends Metric
{
    protected string $temporality = ReportMeasure::POINT_IN_TIME;

    public static function key(): string
    {
        return 'operations';
    }

    protected function compute(ReportPeriod $period): array
    {
        $open = Order::query()
            ->whereNotIn('status', ['completed', ReportMeasure::EXCLUDED_STATUS])
            ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'label' => Order::statusLabel($row->status),
                'count' => (int) $row->count,
                'value' => round((float) $row->total, 2),
            ])
            ->values()
            ->all();

        return [
            'by_status' => $open,
            'open_orders' => array_sum(array_column($open, 'count')),
            'open_value' => round(array_sum(array_column($open, 'value')), 2),
        ];
    }
}
