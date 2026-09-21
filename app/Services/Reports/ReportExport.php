<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

/**
 * A report section as spreadsheet rows.
 *
 * Built from the SAME ReportBuilder payload the screen and the printed document
 * render, so an exported file can never disagree with the page it came from.
 *
 * Numbers go out RAW — 1031.6, not "₱1,031.60". A CSV exists to be summed and
 * charted somewhere else, and a formatted figure arrives in a spreadsheet as
 * text that no formula will touch. Formatting is the renderer's job, not the
 * file's.
 *
 * Sections with two tables (colour performance, payments, the order breakdown)
 * export as one file with a leading "Group" column rather than two downloads,
 * because they answer one question between them.
 */
final class ReportExport
{
    /** Sections with nothing tabular to give a spreadsheet. */
    private const NOT_EXPORTABLE = ['definitions', 'signatories', 'sales_pacing'];

    public static function isExportable(string $key): bool
    {
        return ! in_array($key, self::NOT_EXPORTABLE, true);
    }

    /**
     * @param  array<string, mixed>  $data  the section's metric payloads
     * @return array{filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}|null
     */
    public static function forSection(string $key, string $label, array $data, array $period): ?array
    {
        $table = match ($key) {
            'summary' => self::summary($data),
            'sales_trend' => self::salesTrend($data),
            'order_breakdown' => self::orderBreakdown($data),
            'top_products' => self::simple($data['products']['current']['top'] ?? [], [
                'name' => 'Product', 'brand' => 'Brand', 'revenue' => 'Revenue', 'units' => 'Units',
            ]),
            'colors' => self::colors($data),
            'categories' => self::simple($data['categories']['current']['top'] ?? [], [
                'category' => 'Category', 'revenue' => 'Revenue', 'units' => 'Units',
            ]),
            'customers' => self::simple($data['customers']['current']['top'] ?? [], [
                'customer' => 'Customer', 'orders' => 'Orders', 'revenue' => 'Revenue',
            ]),
            'payments' => self::payments($data),
            'cancellations' => self::simple($data['cancellations']['current']['by_reason'] ?? [], [
                'reason' => 'Reason', 'count' => 'Orders', 'value' => 'Value',
            ]),
            'operations' => self::simple($data['operations']['current']['by_status'] ?? [], [
                'label' => 'Status', 'count' => 'Orders', 'value' => 'Value',
            ]),
            'inventory' => self::inventory($data),
            'dead_stock' => self::simple($data['dead_stock']['current']['top'] ?? [], [
                'product' => 'Product', 'brand' => 'Brand', 'color' => 'Colour',
                'size' => 'Size', 'stock' => 'On hand', 'price' => 'Price', 'tied_up' => 'Tied up',
            ]),
            default => null,
        };

        if ($table === null) {
            return null;
        }

        return $table + ['filename' => self::filename($label, $period)];
    }

    private static function filename(string $label, array $period): string
    {
        return Str::slug($label).'-'.($period['from'] ?? '').'-to-'.($period['to'] ?? '').'.csv';
    }

    /**
     * Rows from a list of associative arrays, using a column map.
     *
     * @param  array<string, string>  $columns  row key => heading
     */
    private static function simple(array $rows, array $columns, array $lead = []): array
    {
        return [
            'headers' => array_merge(array_values($lead), array_values($columns)),
            'rows' => array_map(
                fn (array $row) => array_merge(
                    array_values($lead ? [$row['__group'] ?? ''] : []),
                    array_map(fn (string $column) => $row[$column] ?? null, array_keys($columns)),
                ),
                $rows,
            ),
        ];
    }

    /** Tag rows with the sub-table they came from, for a combined export. */
    private static function group(array $rows, string $group): array
    {
        return array_map(fn (array $row) => $row + ['__group' => $group], $rows);
    }

    private static function summary(array $data): array
    {
        $sales = $data['sales']['current'] ?? [];
        $deltas = $data['sales']['deltas'] ?? [];
        $orders = $data['orders']['current'] ?? [];

        $rows = [];

        foreach ([
            'Revenue' => 'revenue',
            'Orders' => 'orders',
            'Average order value' => 'avg_order_value',
        ] as $label => $key) {
            $rows[] = [
                $label,
                $sales[$key] ?? null,
                $deltas[$key]['previous'] ?? null,
                $deltas[$key]['change'] ?? null,
                $deltas[$key]['percent'] ?? null,
            ];
        }

        $rows[] = ['Orders placed (including cancelled)', $orders['total_orders'] ?? null, null, null, null];

        return [
            'headers' => ['Measure', 'This period', 'Comparison', 'Change', 'Change %'],
            'rows' => $rows,
        ];
    }

    private static function salesTrend(array $data): array
    {
        $current = $data['sales']['current']['series'] ?? [];
        $previous = $data['sales']['previous']['series'] ?? null;

        $rows = [];

        foreach ($current as $index => $point) {
            $rows[] = [
                $point['label'],
                $point['revenue'],
                $point['orders'],
                // Index-aligned, and each comparison point keeps its OWN label
                // so nobody reads it as belonging to the date beside it.
                $previous[$index]['label'] ?? null,
                $previous[$index]['revenue'] ?? null,
                $previous[$index]['orders'] ?? null,
            ];
        }

        return [
            'headers' => ['Period', 'Revenue', 'Orders', 'Comparison period', 'Comparison revenue', 'Comparison orders'],
            'rows' => $rows,
        ];
    }

    private static function orderBreakdown(array $data): array
    {
        $orders = $data['orders']['current'] ?? [];

        $rows = array_merge(
            self::group(array_map(fn ($row) => [
                'name' => $row['label'], 'count' => $row['count'], 'value' => $row['total'],
            ], $orders['by_status'] ?? []), 'By status'),

            self::group(array_map(fn ($row) => [
                'name' => $row['label'], 'count' => $row['count'], 'value' => $row['revenue'],
            ], $orders['by_type'] ?? []), 'Delivery vs pickup'),
        );

        return self::simple($rows, ['name' => 'Name', 'count' => 'Orders', 'value' => 'Value'], ['Group']);
    }

    private static function colors(array $data): array
    {
        $colors = $data['colors']['current'] ?? [];

        $rows = array_merge(
            self::group($colors['factory'] ?? [], 'Factory shade'),
            self::group($colors['custom'] ?? [], 'Custom mix'),
        );

        return self::simple($rows, [
            'label' => 'Colour', 'code' => 'Code', 'hex' => 'Hex',
            'revenue' => 'Revenue', 'quantity' => 'Cans',
        ], ['Group']);
    }

    private static function payments(array $data): array
    {
        $payments = $data['payments']['current'] ?? [];

        $rows = array_merge(
            self::group(array_map(fn ($row) => [
                'name' => $row['label'], 'count' => $row['count'], 'value' => $row['total'],
            ], $payments['by_method'] ?? []), 'By method'),

            self::group(array_map(fn ($row) => [
                'name' => $row['label'], 'count' => $row['count'], 'value' => $row['total'],
            ], $payments['by_status'] ?? []), 'By payment status'),
        );

        return self::simple($rows, ['name' => 'Name', 'count' => 'Orders', 'value' => 'Value'], ['Group']);
    }

    private static function inventory(array $data): array
    {
        $inventory = $data['inventory']['current'] ?? [];

        return [
            'headers' => ['Measure', 'Value', 'As of'],
            'rows' => array_map(
                fn (string $label, $value) => [$label, $value, $data['inventory']['as_of'] ?? null],
                ['Products', 'Variants', 'Cans on hand', 'Stock value', 'Low stock', 'Out of stock'],
                [
                    $inventory['products'] ?? null,
                    $inventory['variants'] ?? null,
                    $inventory['units'] ?? null,
                    $inventory['stock_value'] ?? null,
                    $inventory['low_stock'] ?? null,
                    $inventory['out_of_stock'] ?? null,
                ],
            ),
        ];
    }
}
