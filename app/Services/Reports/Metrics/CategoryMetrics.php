<?php

namespace App\Services\Reports\Metrics;

use App\Models\Product;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * Revenue by category.
 *
 * THESE TOTALS OVERLAP AND DO NOT SUM TO THE PERIOD'S REVENUE. A product can
 * sit in several categories since the category_product pivot landed, and an
 * enamel that is also a wood coating counts its revenue under both — otherwise
 * customers browsing either category would never find it, and the report would
 * have to pick one arbitrarily.
 *
 * So this ranking answers "how much business touched this category", not "how
 * was revenue split". The caveat is carried in the payload and printed on the
 * paper report, because on a signed document a reader will otherwise try to add
 * the column up.
 */
class CategoryMetrics extends Metric
{
    protected array $deltaKeys = [];

    public static function key(): string
    {
        return 'categories';
    }

    protected function compute(ReportPeriod $period): array
    {
        $rows = ReportMeasure::lineItems($period)
            ->selectRaw('order_items.product_id, SUM(order_items.subtotal) as revenue, SUM(order_items.quantity) as units')
            ->groupBy('order_items.product_id')
            ->get();

        $products = Product::with('categories')
            ->whereIn('id', $rows->pluck('product_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $totals = [];

        foreach ($rows as $row) {
            $categories = $products->get($row->product_id)?->categories->pluck('category_name')->all();

            foreach ($categories ?: ['Uncategorized'] as $name) {
                $totals[$name] ??= ['category' => $name, 'revenue' => 0.0, 'units' => 0];
                $totals[$name]['revenue'] += (float) $row->revenue;
                $totals[$name]['units'] += (int) $row->units;
            }
        }

        usort($totals, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'top' => array_map(
                fn ($row) => ['revenue' => round($row['revenue'], 2)] + $row,
                array_slice($totals, 0, $this->topN(8)),
            ),
            'distinct_categories' => count($totals),
            'overlaps' => true,
            'caveat' => 'A product can belong to several categories, so its revenue is counted under each one. These totals overlap and do not add up to the period total.',
        ];
    }
}
