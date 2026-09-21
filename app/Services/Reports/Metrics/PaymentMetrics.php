<?php

namespace App\Services\Reports\Metrics;

use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * How the period's orders were paid for.
 *
 * Anchored to the ORDER's date, like every other period figure, not to
 * payments.payment_date — a figure that moved as payments settled would change
 * between two runs of the same report.
 *
 * `cash` still appears for older orders. Cash on Pickup stopped being offered
 * in September 2026 but stayed in the payments enum so placed orders read back,
 * so the report shows it where it exists rather than pretending it never did.
 */
class PaymentMetrics extends Metric
{
    protected array $deltaKeys = ['paid_value', 'outstanding_value'];

    public static function key(): string
    {
        return 'payments';
    }

    protected function compute(ReportPeriod $period): array
    {
        $rows = ReportMeasure::orders($period)
            ->leftJoin('payments', 'payments.order_id', '=', 'orders.id')
            ->selectRaw('payments.payment_method, payments.payment_status, COUNT(*) as count, SUM(orders.total_amount) as total')
            ->groupBy('payments.payment_method', 'payments.payment_status')
            ->get();

        $byMethod = [];
        $byStatus = [];
        $paid = 0.0;
        $outstanding = 0.0;

        foreach ($rows as $row) {
            $method = $row->payment_method ?: 'unrecorded';
            $status = $row->payment_status ?: 'unrecorded';
            $total = (float) $row->total;

            $byMethod[$method] ??= ['method' => $method, 'label' => $this->methodLabel($method), 'count' => 0, 'total' => 0.0];
            $byMethod[$method]['count'] += (int) $row->count;
            $byMethod[$method]['total'] += $total;

            $byStatus[$status] ??= ['status' => $status, 'label' => ucfirst($status), 'count' => 0, 'total' => 0.0];
            $byStatus[$status]['count'] += (int) $row->count;
            $byStatus[$status]['total'] += $total;

            if ($status === 'paid') {
                $paid += $total;
            } elseif (in_array($status, ['pending', 'unrecorded'], true)) {
                $outstanding += $total;
            }
        }

        $sort = function (array $rows) {
            usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

            return array_map(fn ($row) => ['total' => round($row['total'], 2)] + $row, $rows);
        };

        return [
            'by_method' => $sort(array_values($byMethod)),
            'by_status' => $sort(array_values($byStatus)),
            'paid_value' => round($paid, 2),
            'outstanding_value' => round($outstanding, 2),
        ];
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'cod' => 'Cash on Delivery',
            'gcash' => 'GCash',
            'card' => 'Card',
            'cash' => 'Cash on Pickup (retired)',
            'unrecorded' => 'No payment record',
            default => ucfirst($method),
        };
    }
}
