<?php

namespace App\Support\Reports;

use App\Services\Reports\Metrics\CancellationMetrics;
use App\Services\Reports\Metrics\CategoryMetrics;
use App\Services\Reports\Metrics\ColorMetrics;
use App\Services\Reports\Metrics\CustomerMetrics;
use App\Services\Reports\Metrics\DeadStockMetrics;
use App\Services\Reports\Metrics\InventoryMetrics;
use App\Services\Reports\Metrics\OperationsMetrics;
use App\Services\Reports\Metrics\OrderMetrics;
use App\Services\Reports\Metrics\PaymentMetrics;
use App\Services\Reports\Metrics\ProductMetrics;
use App\Services\Reports\Metrics\SalesMetrics;

/**
 * The catalogue of report sections.
 *
 * Adding a report means adding an entry here plus two small partials. Nothing
 * else in the system needs to learn about it: the screen page, the build-report
 * checkboxes and the printed document are all driven off this list.
 */
final class ReportSections
{
    public const GROUP_SUMMARY = 'Summary';

    public const GROUP_SALES = 'Sales';

    public const GROUP_CATALOGUE = 'Catalogue';

    public const GROUP_CUSTOMERS = 'Customers';

    public const GROUP_OPERATIONS = 'Operations';

    public const GROUP_INVENTORY = 'Inventory';

    public const GROUP_REFERENCE = 'Reference';

    /** @return array<string, ReportSection> */
    public static function all(): array
    {
        $sections = [
            new ReportSection(
                key: 'summary',
                label: 'Executive summary',
                group: self::GROUP_SUMMARY,
                metrics: [SalesMetrics::class, OrderMetrics::class],
                description: 'Revenue, order volume and average order value, against the comparison period.',
                // Four tiles and a line of prose - splitting it gains nothing.
                pageBreak: ReportSection::BREAK_AVOID,
            ),

            new ReportSection(
                key: 'sales_trend',
                label: 'Revenue over time',
                group: self::GROUP_SALES,
                metrics: [SalesMetrics::class],
                description: 'The trend across the period, with the comparison window behind it.',
                hasChart: true,
            ),

            new ReportSection(
                key: 'sales_pacing',
                label: 'Cumulative pacing',
                group: self::GROUP_SALES,
                metrics: [SalesMetrics::class],
                description: 'Running revenue total — whether the period is ahead of or behind the one it is measured against.',
                defaultEnabled: false,
                hasChart: true,
            ),

            new ReportSection(
                key: 'order_breakdown',
                label: 'Orders by status and type',
                group: self::GROUP_SALES,
                metrics: [OrderMetrics::class],
                description: 'Where the period\'s orders ended up, and the delivery/pickup split.',
                hasChart: true,
                // A short bar list beside a three-row table.
                pageBreak: ReportSection::BREAK_AVOID,
            ),

            new ReportSection(
                key: 'top_products',
                label: 'Top products',
                group: self::GROUP_CATALOGUE,
                metrics: [ProductMetrics::class],
                description: 'Ranked by line revenue.',
                hasChart: true,
            ),

            new ReportSection(
                key: 'colors',
                label: 'Colour performance',
                group: self::GROUP_CATALOGUE,
                metrics: [ColorMetrics::class],
                description: 'Best-selling factory shades and custom mixes, with swatches.',
                hasChart: true,
            ),

            new ReportSection(
                key: 'categories',
                label: 'Revenue by category',
                group: self::GROUP_CATALOGUE,
                metrics: [CategoryMetrics::class],
                description: 'Overlapping totals — a product counts under every category it belongs to.',
                hasChart: true,
            ),

            new ReportSection(
                key: 'customers',
                label: 'Customers',
                group: self::GROUP_CUSTOMERS,
                metrics: [CustomerMetrics::class],
                description: 'First-time versus returning buyers, and who spent the most.',
            ),

            new ReportSection(
                key: 'payments',
                label: 'Payment methods',
                group: self::GROUP_CUSTOMERS,
                metrics: [PaymentMetrics::class],
                description: 'How orders were paid for, and what is still outstanding.',
                hasChart: true,
            ),

            new ReportSection(
                key: 'cancellations',
                label: 'Cancellations',
                group: self::GROUP_OPERATIONS,
                metrics: [CancellationMetrics::class],
                description: 'Rate, reasons, who cancelled, and the value lost.',
            ),

            new ReportSection(
                key: 'operations',
                label: 'Open orders (as of now)',
                group: self::GROUP_OPERATIONS,
                metrics: [OperationsMetrics::class],
                description: 'Work still outstanding at this moment, whenever those orders were placed.',
                // A couple of tiles and a handful of statuses.
                pageBreak: ReportSection::BREAK_AVOID,
            ),

            new ReportSection(
                key: 'inventory',
                label: 'Stock snapshot (as of now)',
                group: self::GROUP_INVENTORY,
                metrics: [InventoryMetrics::class],
                description: 'Units, stock value, and how many variants are low or out.',
                // Four tiles.
                pageBreak: ReportSection::BREAK_AVOID,
            ),

            new ReportSection(
                key: 'dead_stock',
                label: 'Dead stock',
                group: self::GROUP_INVENTORY,
                metrics: [DeadStockMetrics::class],
                description: 'Cans on the shelf that sold nothing in the period, and the capital tied up in them.',
                defaultEnabled: false,
            ),

            new ReportSection(
                key: 'definitions',
                label: 'Definitions',
                group: self::GROUP_REFERENCE,
                description: 'How each figure on this report was computed.',
                defaultEnabled: false,
            ),

            new ReportSection(
                key: 'signatories',
                label: 'Certification & approval',
                group: self::GROUP_REFERENCE,
                description: 'Prepared by / reviewed by / approved by panel.',
                printOnly: true,
                // Signatures split across a page boundary would be worthless.
                pageBreak: ReportSection::BREAK_AVOID,
            ),
        ];

        return collect($sections)->keyBy('key')->all();
    }

    /** @return array<string, array<int, ReportSection>> */
    public static function grouped(): array
    {
        return collect(self::all())->groupBy('group')->map->all()->all();
    }

    /** @return array<int, string> */
    public static function defaultKeys(): array
    {
        return collect(self::all())
            ->filter(fn (ReportSection $section) => $section->defaultEnabled)
            ->keys()
            ->all();
    }

    /**
     * Turn a list of keys into sections, in CATALOGUE order rather than the
     * order they were ticked — a report should read the same way whichever
     * order its blocks were chosen in.
     *
     * Unknown keys are dropped rather than throwing, so a saved preset that
     * names a section which has since been renamed still opens.
     *
     * @param  array<int, string>  $keys
     * @return array<int, ReportSection>
     */
    public static function resolve(array $keys): array
    {
        return collect(self::all())
            ->filter(fn (ReportSection $section) => in_array($section->key, $keys, true))
            ->values()
            ->all();
    }
}
