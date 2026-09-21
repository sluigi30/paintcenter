<?php

namespace App\Services\Reports\Metrics;

use App\Models\ProductVariant;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * Cans sitting on the shelf that nothing sold in the reporting period.
 *
 * Point-in-time, because the stock half is "what is on the shelf now"; the
 * sales half looks back across the period. Both halves are named in the payload
 * so the paper cannot imply the whole thing describes the window.
 *
 * This reads line items WITHOUT the mix roll-up, on purpose. A tint poured into
 * a custom mix is stock that really left the shelf, so a colourant that only
 * ever sells inside mixes is NOT dead stock — rolling those rows up would hide
 * the shop's fastest-moving inventory and recommend clearing it out.
 */
class DeadStockMetrics extends Metric
{
    protected string $temporality = ReportMeasure::POINT_IN_TIME;

    public static function key(): string
    {
        return 'dead_stock';
    }

    protected function compute(ReportPeriod $period): array
    {
        $sold = ReportMeasure::lineItems($period)
            ->whereNotNull('order_items.product_variant_id')
            ->distinct()
            ->pluck('order_items.product_variant_id')
            ->all();

        $query = ProductVariant::query()
            ->active()
            ->where('stock', '>', 0)
            ->when($sold, fn ($q) => $q->whereNotIn('id', $sold))
            ->with('product.brand');

        $count = (clone $query)->count();
        $value = round((float) (clone $query)->selectRaw('SUM(stock * price) as value')->value('value'), 2);

        $rows = $query
            ->orderByRaw('stock * price DESC')
            ->limit($this->topN(15))
            ->get()
            ->map(fn (ProductVariant $variant) => [
                'variant_id' => $variant->id,
                'product' => $variant->product?->name ?: 'Unknown product',
                'brand' => $variant->product?->brand?->brand_name ?? '-',
                'color' => $variant->color_label ?: 'No colour',
                'size' => $variant->size_volume,
                'stock' => (int) $variant->stock,
                'price' => round((float) $variant->price, 2),
                'tied_up' => round((float) $variant->stock * (float) $variant->price, 2),
            ])
            ->all();

        return [
            'variants' => $count,
            'capital' => $value,
            'top' => $rows,
            'sales_window' => $period->label(),
        ];
    }
}
