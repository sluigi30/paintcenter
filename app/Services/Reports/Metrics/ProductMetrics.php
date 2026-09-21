<?php

namespace App\Services\Reports\Metrics;

use App\Models\Product;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * Top products by line revenue.
 *
 * DELIBERATELY DOES NOT ROLL UP MIX GROUPS, unlike ColorMetrics. When a can is
 * tinted at the counter, the base product and every colourant product really
 * did sell — the tint is stock that left the shelf and money that was charged.
 * Rolling those lines into the base would hide the shop's fastest-moving
 * inventory from the product report.
 *
 * The roll-up in ColorMetrics is about a different question: a mix is ONE
 * colour, however many cans it was poured from. Both views sum to the same line
 * revenue, which is what the reconciliation test asserts.
 */
class ProductMetrics extends Metric
{
    protected array $deltaKeys = ['revenue', 'units', 'distinct_products'];

    public static function key(): string
    {
        return 'products';
    }

    protected function compute(ReportPeriod $period): array
    {
        $rows = ReportMeasure::lineItems($period)
            ->selectRaw('order_items.product_id, SUM(order_items.subtotal) as revenue, SUM(order_items.quantity) as units')
            ->groupBy('order_items.product_id')
            ->orderByDesc('revenue')
            ->get();

        $products = Product::with('brand')
            ->whereIn('id', $rows->pluck('product_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $top = $rows->take($this->topN(10))->map(function ($row) use ($products) {
            $product = $products->get($row->product_id);

            return [
                'product_id' => (int) $row->product_id,
                'name' => $product?->name ?: 'Deleted product',
                'brand' => $product?->brand?->brand_name ?? '-',
                'revenue' => round((float) $row->revenue, 2),
                'units' => (int) $row->units,
            ];
        })->values()->all();

        return [
            'top' => $top,
            'revenue' => round((float) $rows->sum('revenue'), 2),
            'units' => (int) $rows->sum('units'),
            'distinct_products' => $rows->count(),
        ];
    }
}
