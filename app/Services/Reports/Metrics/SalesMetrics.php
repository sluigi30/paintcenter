<?php

namespace App\Services\Reports\Metrics;

use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * Headline sales: revenue, order volume, average order value, and the trend.
 *
 * Every figure here is ORDER revenue over COUNTABLE orders. Both parts matter:
 * the page this replaces divided cancelled-excluded revenue by a
 * cancelled-included order count, so its average order value was understated
 * by the cancellation rate, and its KPI card disagreed with its own chart
 * about how many orders the period contained.
 */
class SalesMetrics extends Metric
{
    protected array $deltaKeys = ['revenue', 'orders', 'avg_order_value'];

    public static function key(): string
    {
        return 'sales';
    }

    protected function compute(ReportPeriod $period): array
    {
        $revenue = ReportMeasure::orderRevenue($period);
        $orders = ReportMeasure::orderCount($period);

        $expression = ReportPeriod::dayExpression('orders.created_at');

        $rows = ReportMeasure::orders($period)
            ->selectRaw("{$expression} as day, SUM(total_amount) as revenue, COUNT(*) as orders")
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->get();

        $series = $period->foldDaily($rows, 'day', [
            'revenue' => 'revenue',
            'orders' => 'orders',
        ]);

        return [
            'revenue' => $revenue,
            'orders' => $orders,

            // Same countable set on both sides of the division. This is the
            // whole point of routing through ReportMeasure.
            'avg_order_value' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,

            'series' => $series,
            'cumulative' => $this->runningTotal($series),

            'best_day' => $this->peak($series),
        ];
    }

    /**
     * Running revenue total, so the chart can answer "are we ahead of where we
     * were at this point last period" instead of only "which day was busy".
     */
    private function runningTotal(array $series): array
    {
        $total = 0.0;

        return array_map(function (array $point) use (&$total) {
            $total += (float) $point['revenue'];

            return [
                'key' => $point['key'],
                'label' => $point['label'],
                'revenue' => round($total, 2),
            ];
        }, $series);
    }

    private function peak(array $series): ?array
    {
        $best = null;

        foreach ($series as $point) {
            if ($point['revenue'] > 0 && ($best === null || $point['revenue'] > $best['revenue'])) {
                $best = $point;
            }
        }

        return $best;
    }
}
