<?php

namespace App\Services\Reports\Metrics;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * Stock as it stands RIGHT NOW.
 *
 * POINT-IN-TIME, and that is the whole reason this is its own metric. The page
 * this replaces printed live stock counts under a letterhead naming a past
 * reporting period, so a report for last month claimed last month had today's
 * twelve pending orders. Nothing here is compared against a previous window
 * either — there is only one present moment — and every figure renders with the
 * as-of stamp the base class attaches.
 *
 * Stock lives on the VARIANT, never the product: a variant is one can on the
 * shelf, priced and counted on its own.
 */
class InventoryMetrics extends Metric
{
    protected string $temporality = ReportMeasure::POINT_IN_TIME;

    public static function key(): string
    {
        return 'inventory';
    }

    protected function compute(ReportPeriod $period): array
    {
        $active = ProductVariant::query()->active();

        return [
            'products' => Product::where('is_archived', false)->count(),
            'variants' => (clone $active)->count(),
            'units' => (int) (clone $active)->sum('stock'),
            'stock_value' => round((float) (clone $active)->selectRaw('SUM(stock * price) as value')->value('value'), 2),
            'low_stock' => (clone $active)->lowStock()->where('stock', '>', 0)->count(),
            'out_of_stock' => (clone $active)->outOfStock()->count(),
        ];
    }
}
