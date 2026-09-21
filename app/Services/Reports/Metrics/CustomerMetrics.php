<?php

namespace App\Services\Reports\Metrics;

use App\Models\User;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * Who bought, and whether they had bought before.
 *
 * Two different "new" are kept apart on purpose, because the old page reported
 * one and labelled it the other:
 *
 *   NEW REGISTRATIONS  — accounts created in the period. Some never order.
 *   FIRST-TIME BUYERS  — customers whose first-ever countable order falls in
 *                        this period. This is the one that drives revenue.
 *
 * A customer's first order is looked up across ALL time, not just the window,
 * or every customer would look new in every report.
 */
class CustomerMetrics extends Metric
{
    protected array $deltaKeys = [
        'new_registrations',
        'first_time_buyers',
        'returning_buyers',
        'new_revenue',
        'returning_revenue',
    ];

    public static function key(): string
    {
        return 'customers';
    }

    protected function compute(ReportPeriod $period): array
    {
        $inPeriod = ReportMeasure::orders($period)
            ->selectRaw('user_id, COUNT(*) as orders, SUM(total_amount) as revenue')
            ->groupBy('user_id')
            ->get();

        // First countable order per customer, across all time.
        $firstOrders = ReportMeasure::countableOrders()
            ->whereIn('user_id', $inPeriod->pluck('user_id')->filter()->all())
            ->selectRaw('user_id, MIN('.ReportMeasure::DATE_ANCHOR.') as first_at')
            ->groupBy('user_id')
            ->pluck('first_at', 'user_id');

        $split = [
            'new' => ['buyers' => 0, 'revenue' => 0.0],
            'returning' => ['buyers' => 0, 'revenue' => 0.0],
        ];

        foreach ($inPeriod as $row) {
            $firstAt = $firstOrders[$row->user_id] ?? null;
            $isNew = $firstAt !== null && CarbonImmutable::parse($firstAt)->gte($period->from);

            $bucket = $isNew ? 'new' : 'returning';
            $split[$bucket]['buyers']++;
            $split[$bucket]['revenue'] += (float) $row->revenue;
        }

        ['new' => $new, 'returning' => $returning] = $split;

        $users = User::whereIn('id', $inPeriod->pluck('user_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $top = $inPeriod->sortByDesc('revenue')->take($this->topN(10))->map(function ($row) use ($users) {
            $user = $users->get($row->user_id);

            return [
                'customer' => $user
                    ? (trim($user->first_name.' '.$user->last_name) ?: ($user->email ?? 'Customer'))
                    : 'Deleted customer',
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ];
        })->values()->all();

        return [
            'new_registrations' => User::where('role', 'customer')
                ->whereBetween(ReportMeasure::DATE_ANCHOR, [$period->from, $period->to])
                ->count(),

            'buyers' => $inPeriod->count(),
            'first_time_buyers' => $new['buyers'],
            'returning_buyers' => $returning['buyers'],
            'new_revenue' => round($new['revenue'], 2),
            'returning_revenue' => round($returning['revenue'], 2),

            'top' => $top,
        ];
    }
}
