<?php

namespace App\Services\Reports;

/**
 * Chart configuration for a built report.
 *
 * Lives in PHP rather than in the Blade partials for two reasons, both of which
 * were bugs:
 *
 *  1. A canvas sits behind wire:ignore so a Livewire morph cannot blank it —
 *     which also means an updated data-chart attribute never reaches the DOM.
 *     With the specs built in the template there was nothing for the page to
 *     hand the browser, so changing the grouping or the dates redrew the chart
 *     from its STALE attribute and nothing appeared to change until a manual
 *     refresh. Built here, the page can dispatch the new specs with the update.
 *
 *  2. Overlaying the comparison period on one chart plots it against the
 *     CURRENT period's axis labels. For two equal-length windows that is merely
 *     loose; for a year-over-year window across a leap year, or any custom
 *     range of a different length, the comparison line is drawn against dates
 *     that are not its own.
 *
 * Hence two layouts. Side by side gives each period its own honest axis, and
 * the pair is locked to a SHARED y scale — without that, two auto-scaled charts
 * draw the same-looking line whatever the magnitudes, which is worse than the
 * overlay it replaced.
 */
final class ReportCharts
{
    public const LAYOUT_SPLIT = 'split';

    public const LAYOUT_OVERLAY = 'overlay';

    public const LAYOUTS = [
        self::LAYOUT_SPLIT => 'Side by side',
        self::LAYOUT_OVERLAY => 'Overlaid',
    ];

    /**
     * @return array<string, array<string, mixed>> keyed by section
     */
    public static function forReport(array $sections, array $period, string $layout): array
    {
        $charts = [];

        foreach ($sections as $section) {
            $sales = $section['data']['sales'] ?? null;

            if ($sales === null) {
                continue;
            }

            $charts[$section['key']] = match ($section['key']) {
                'sales_trend' => [
                    'groups' => [
                        self::group('rptRevenue', 'Revenue', $sales, 'series', 'revenue', 'money', $period, $layout),
                        self::group('rptOrders', 'Orders', $sales, 'series', 'orders', 'count', $period, $layout),
                    ],
                ],
                'sales_pacing' => [
                    'groups' => [
                        self::group('rptPacing', 'Running revenue total', $sales, 'cumulative', 'revenue', 'money', $period, $layout),
                    ],
                ],
                default => null,
            };
        }

        return array_filter($charts);
    }

    /**
     * One measure, rendered either as a single overlaid chart or as a pair.
     */
    private static function group(
        string $id,
        string $caption,
        array $sales,
        string $seriesKey,
        string $valueKey,
        string $format,
        array $period,
        string $layout,
    ): array {
        $current = $sales['current'][$seriesKey] ?? [];
        $previous = $sales['previous'][$seriesKey] ?? null;

        $currentData = array_column($current, $valueKey);

        if ($previous === null) {
            return [
                'caption' => $caption,
                'charts' => [[
                    'id' => $id,
                    'title' => null,
                    'spec' => self::spec($current, [
                        ['label' => $caption, 'role' => 'primary', 'data' => $currentData],
                    ], $format),
                ]],
            ];
        }

        $previousData = array_column($previous, $valueKey);

        if ($layout === self::LAYOUT_OVERLAY) {
            return [
                'caption' => $caption,
                'charts' => [[
                    'id' => $id,
                    'title' => null,
                    'spec' => self::spec($current, [
                        ['label' => 'This period', 'role' => 'primary', 'data' => $currentData],
                        ['label' => 'Comparison', 'role' => 'ghost', 'data' => $previousData],
                    ], $format),
                ]],
            ];
        }

        // Locked to one scale across the pair. Two independently scaled charts
        // would draw a halved period identically to a doubled one.
        $ceiling = self::ceiling(array_merge($currentData, $previousData), $format);

        return [
            'caption' => $caption,
            'shared_scale' => true,
            'charts' => [
                [
                    'id' => $id,
                    'title' => $period['label'],
                    'spec' => self::spec($current, [
                        ['label' => 'This period', 'role' => 'primary', 'data' => $currentData],
                    ], $format, $ceiling),
                ],
                [
                    'id' => $id.'Compare',
                    // Its own dates, which is the whole point of splitting.
                    'title' => $period['compare_label'],
                    'spec' => self::spec($previous, [
                        ['label' => 'Comparison', 'role' => 'ghost', 'data' => $previousData],
                    ], $format, $ceiling),
                ],
            ],
        ];
    }

    /**
     * Few enough points and a line is the wrong form: it draws a trend between
     * marks that have no trend between them, and at a single point it draws
     * nothing at all. Quarterly and annual grouping hit this immediately — a
     * one-year range grouped by year is one bucket.
     */
    private const BARS_AT_OR_BELOW = 3;

    private static function spec(array $series, array $datasets, string $format, ?float $max = null): array
    {
        return array_filter([
            'type' => count($series) <= self::BARS_AT_OR_BELOW ? 'bar' : 'line',
            'labels' => array_column($series, 'label'),
            'value' => $format,
            'series' => $datasets,
            'y_max' => $max,
        ], fn ($value) => $value !== null);
    }

    /**
     * A shared axis ceiling: a little headroom above the tallest point, rounded
     * UP to a number an axis can be labelled with.
     *
     * Multiplying the maximum by a headroom factor and stopping there put
     * "7.5600000000000005" at the top of the orders axis — binary floating
     * point, and a fractional ceiling on a count of orders besides. There is no
     * such thing as 7.56 orders, so a count axis rounds to a whole number, and
     * both kinds round to a step that divides cleanly (1, 2, 2.5, 5 x 10^n)
     * rather than to whatever the data happened to reach.
     */
    private static function ceiling(array $values, string $format): float
    {
        $max = empty($values) ? 0.0 : (float) max($values);

        if ($max <= 0) {
            return 1.0;
        }

        $target = $max * 1.08;
        $counts = $format === 'count';

        if ($counts) {
            $target = ceil($target);
        }

        $magnitude = 10 ** floor(log10($target));

        // Fine enough that the ceiling stays near the data: a coarse list put
        // an axis of 104 orders at 200, wasting half the chart's height.
        foreach ([1, 1.2, 1.25, 1.5, 1.75, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
            $candidate = $step * $magnitude;

            if ($candidate >= $target) {
                // round() also clears the binary-float residue that started this.
                return $counts ? (float) (int) ceil($candidate) : round($candidate, 2);
            }
        }

        return $counts ? ceil($target) : round($target, 2);
    }

    /**
     * Flat map of canvas id to spec — what the page dispatches to the browser
     * when anything changes.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function flatten(array $charts): array
    {
        $specs = [];

        foreach ($charts as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['charts'] as $chart) {
                    $specs[$chart['id']] = $chart['spec'];
                }
            }
        }

        return $specs;
    }
}
