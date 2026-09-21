<?php

namespace App\Services\Reports\Metrics;

use App\Services\Reports\Metric;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;

/**
 * Which shades actually sell.
 *
 * Colour became a variant axis in September 2026 and nothing reported on it —
 * the old Top Products table grouped by product, so "BOYSEN Latex Colors"
 * appeared as one row whether the shop sold forty shades or one, and the
 * swatch beside it was decorative.
 *
 * Two segments, because they are two different businesses:
 *
 *   FACTORY shades come off the shelf, identified by the manufacturer's
 *   colour code and name — the identity customers order by.
 *
 *   CUSTOM MIXES are tinted at the counter. A mix is several order lines (a
 *   base can plus the tints poured in) sharing a mix_group, and each line
 *   carries the predicted result colour. Its revenue is counted ONCE for the
 *   whole mix and its quantity is the number of finished cans — see the
 *   roll-up rule in ReportMeasure. Counting the base and each tint as separate
 *   sellers would put colourant at the top of the colour report.
 */
class ColorMetrics extends Metric
{
    protected array $deltaKeys = ['factory_revenue', 'custom_revenue', 'custom_share'];

    public static function key(): string
    {
        return 'colors';
    }

    protected function compute(ReportPeriod $period): array
    {
        // Finest grain the roll-up needs: one row per mix group per role, and
        // one row per distinct colour identity for everything else.
        $rows = ReportMeasure::lineItems($period)
            ->selectRaw(implode(', ', [
                'order_items.mix_group',
                'order_items.mix_role',
                'order_items.color_code',
                'order_items.color_name',
                'order_items.hex_code',
                'order_items.custom_hex',
                'order_items.custom_color_name',
                'SUM(order_items.subtotal) as revenue',
                'SUM(order_items.quantity) as quantity',
            ]))
            ->groupBy(
                'order_items.mix_group',
                'order_items.mix_role',
                'order_items.color_code',
                'order_items.color_name',
                'order_items.hex_code',
                'order_items.custom_hex',
                'order_items.custom_color_name',
            )
            ->get()
            ->map(fn ($row) => (array) $row->getAttributes())
            ->all();

        // One row per mix group from here on; un-mixed lines pass through.
        $rolled = ReportMeasure::rollUpMixGroups($rows);

        $groups = ['factory' => [], 'custom' => []];

        foreach ($rolled as $row) {
            $isCustom = ! empty($row['custom_hex']);
            $segment = $isCustom ? 'custom' : 'factory';

            // Colour identity is the code AND the name together, never the
            // code alone — plenty of shades ship named and uncoded, and keying
            // on the code would collapse every one of them into a single row.
            $identity = $isCustom
                ? strtolower((string) $row['custom_hex']).'|'.($row['custom_color_name'] ?? '')
                : ($row['color_code'] ?? '').'|'.($row['color_name'] ?? '');

            $groups[$segment][$identity] ??= [
                'segment' => $segment,
                'label' => $this->label($row, $isCustom),
                'code' => $isCustom ? null : ($row['color_code'] ?: null),
                'hex' => $isCustom ? $row['custom_hex'] : ($row['hex_code'] ?: null),
                'revenue' => 0.0,
                'quantity' => 0,
                'mixes' => 0,
            ];

            $groups[$segment][$identity]['revenue'] += (float) ($row['revenue'] ?? 0);
            $groups[$segment][$identity]['quantity'] += (int) ($row['quantity'] ?? 0);

            if (! empty($row['mix_group'])) {
                $groups[$segment][$identity]['mixes']++;
            }
        }

        $factory = $groups['factory'];
        $custom = $groups['custom'];

        $factoryRevenue = array_sum(array_column($factory, 'revenue'));
        $customRevenue = array_sum(array_column($custom, 'revenue'));
        $total = $factoryRevenue + $customRevenue;

        return [
            'factory' => $this->rank($factory),
            'custom' => $this->rank($custom),
            'factory_revenue' => round($factoryRevenue, 2),
            'custom_revenue' => round($customRevenue, 2),
            'custom_share' => $total > 0 ? round(($customRevenue / $total) * 100, 1) : 0.0,
            'distinct_shades' => count($factory),
            'distinct_mixes' => count($custom),
        ];
    }

    /** "Burnt Sienna (B-1408)" for a factory shade, the customer's label for a mix. */
    private function label(array $row, bool $isCustom): string
    {
        if ($isCustom) {
            return $row['custom_color_name'] ?: 'Custom mix';
        }

        $name = trim((string) ($row['color_name'] ?? ''));
        $code = trim((string) ($row['color_code'] ?? ''));

        if ($name !== '' && $code !== '') {
            return "{$name} ({$code})";
        }

        // Plenty of shades ship named and uncoded, and thinners and tools are
        // sold in no colour at all.
        return $name ?: ($code ?: 'No colour');
    }

    private function rank(array $rows): array
    {
        $rows = array_values($rows);

        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return array_map(
            fn ($row) => ['revenue' => round($row['revenue'], 2)] + $row,
            array_slice($rows, 0, $this->topN(10)),
        );
    }
}
