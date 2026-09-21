<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the words on a report mean.
 *
 * Centralising the QUERIES is not enough on its own. The page this replaces
 * had no second definition of revenue anywhere — and still shipped a wrong
 * average order value, because one query filtered cancelled orders out and the
 * one feeding its denominator did not. Two readings of "order", three broken
 * figures. So the definitions live here, every metric asks for them by name,
 * and interpreting one differently has to be done on purpose.
 *
 * ---------------------------------------------------------------------------
 * THE TWO REVENUE MEASURES
 *
 *   ORDER REVENUE  = SUM(orders.total_amount)
 *       What the customer was charged. The headline figure: KPIs, the sales
 *       trend, comparisons, customer and payment breakdowns.
 *
 *   LINE REVENUE   = SUM(order_items.subtotal)
 *       The only revenue that can be attributed to a product, variant, colour
 *       or category, because only line items carry those.
 *
 * Today these are equal, and equal by construction: OrderController::store
 * accumulates total_amount as the sum of the line subtotals, and a tint charge
 * is already inside the line's unit_price. There is no delivery fee, discount
 * or voucher in the schema.
 *
 * That equality is ASSERTED BY TEST, not assumed. The day an order-level
 * charge is introduced the assertion fails, and someone decides on purpose
 * whether it belongs in category revenue — rather than every attribution
 * report quietly drifting away from the headline number.
 *
 * ---------------------------------------------------------------------------
 * WHICH ORDERS COUNT
 *
 * Cancelled orders are excluded from every revenue and volume figure, without
 * exception. Pending orders ARE counted: the money is booked at placement, and
 * excluding them would make the current day's figures shrink retroactively as
 * orders move through the flow.
 *
 * ---------------------------------------------------------------------------
 * DATE ANCHOR
 *
 * Every period figure is anchored to orders.created_at — when the order was
 * placed. Three plausible columns exist (created_at, order_date,
 * payments.payment_date) and reports must not mix them; a figure anchored to
 * payment date would move between runs as payments settle.
 *
 * ---------------------------------------------------------------------------
 * MIXED CANS
 *
 * A customer-mixed colour is several order_items rows sharing a mix_group: one
 * base can plus the tint cans poured into it. The roll-up rule is
 * dimension-specific, and it has to stay that way:
 *
 *   REVENUE and QUANTITY roll up to the mix group.
 *       A mix is one thing the customer bought. Revenue is the sum of the
 *       group's line subtotals, counted ONCE at group level and never once per
 *       component. Quantity is the BASE line's quantity — that is how many
 *       cans of finished paint exist; summing the tint rows would count the
 *       pints poured in as if they were cans sold.
 *
 *   STOCK MOVEMENT does not roll up.
 *       A tint consumed inside a mix is a real deduction against that tint's
 *       own variant. Dead-stock and sell-through must see those rows
 *       individually, or the fastest-moving stock in the shop looks untouched.
 */
final class ReportMeasure
{
    /** Period figures cover the range. Point-in-time figures are "as of now". */
    public const PERIOD = 'period';

    public const POINT_IN_TIME = 'point_in_time';

    /** The column every period figure is anchored to. */
    public const DATE_ANCHOR = 'created_at';

    /** Status excluded from every revenue and volume figure. */
    public const EXCLUDED_STATUS = 'cancelled';

    // -- Order-level ---------------------------------------

    /**
     * Orders that count toward any figure. The single gate — no metric writes
     * its own status filter.
     */
    public static function countableOrders(): Builder
    {
        return Order::query()->where('status', '!=', self::EXCLUDED_STATUS);
    }

    /** Countable orders placed inside the period. */
    public static function orders(ReportPeriod $period): Builder
    {
        return self::countableOrders()
            ->whereBetween('orders.'.self::DATE_ANCHOR, [$period->from, $period->to]);
    }

    /**
     * EVERY order in the period including cancelled ones — for the status
     * breakdown and the cancellation report, which exist to show exactly what
     * the countable gate removes. Never for a revenue figure.
     */
    public static function allOrders(ReportPeriod $period): Builder
    {
        return Order::query()
            ->whereBetween('orders.'.self::DATE_ANCHOR, [$period->from, $period->to]);
    }

    public static function orderRevenue(ReportPeriod $period): float
    {
        return (float) self::orders($period)->sum('total_amount');
    }

    public static function orderCount(ReportPeriod $period): int
    {
        return self::orders($period)->count();
    }

    // -- Line-level ----------------------------------------

    /**
     * Line items belonging to countable orders in the period, as a join rather
     * than whereHas so the aggregates stay one query.
     */
    public static function lineItems(ReportPeriod $period): Builder
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '!=', self::EXCLUDED_STATUS)
            ->whereBetween('orders.'.self::DATE_ANCHOR, [$period->from, $period->to]);
    }

    public static function lineRevenue(ReportPeriod $period): float
    {
        return (float) self::lineItems($period)->sum('order_items.subtotal');
    }

    // -- Mix roll-up ---------------------------------------

    /**
     * Collapse the rows of each mix group into one row, per the rule in this
     * class's docblock: revenue sums across the group, quantity comes from the
     * base line. Un-mixed rows pass through untouched.
     *
     * Rows must carry: mix_group, mix_role, revenue, quantity — plus whatever
     * identity columns the caller groups by.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function rollUpMixGroups(iterable $rows): array
    {
        $plain = [];
        $mixes = [];

        foreach ($rows as $row) {
            $row = (array) $row;

            if (empty($row['mix_group'])) {
                $plain[] = $row;

                continue;
            }

            $group = $row['mix_group'];

            if (! isset($mixes[$group])) {
                // Seed from the first row seen so the group always has an
                // identity, then let the base row overwrite it below.
                $mixes[$group] = $row + ['revenue' => 0, 'quantity' => 0];
                $mixes[$group]['revenue'] = 0;
                $mixes[$group]['quantity'] = 0;
            }

            // Revenue: every component line, counted once at group level.
            $mixes[$group]['revenue'] += (float) ($row['revenue'] ?? 0);

            // Quantity and identity: the base line only.
            if (($row['mix_role'] ?? null) === 'base') {
                $mixes[$group]['quantity'] = (int) ($row['quantity'] ?? 0);

                foreach ($row as $column => $value) {
                    if (! in_array($column, ['revenue', 'quantity'], true)) {
                        $mixes[$group][$column] = $value;
                    }
                }
            }
        }

        return array_merge($plain, array_values($mixes));
    }

    // -- Glossary ------------------------------------------

    /**
     * Plain-language definitions, rendered as the optional Definitions section
     * on the printed report. A signed document should be able to answer "how
     * was this figure computed" without anyone opening the code.
     *
     * @return array<int, array{term: string, definition: string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'term' => 'Order revenue',
                'definition' => 'The total charged across all orders placed in the period, excluding cancelled orders.',
            ],
            [
                'term' => 'Line revenue',
                'definition' => 'The same money seen per line item, which is how revenue is attributed to a product, colour or category. Equal to order revenue; the two are reconciled by test.',
            ],
            [
                'term' => 'Which orders count',
                'definition' => 'Cancelled orders are excluded from every revenue and volume figure. Pending and in-progress orders are included — revenue is booked when the order is placed.',
            ],
            [
                'term' => 'Date anchor',
                'definition' => 'Figures are dated by when the order was placed, not when it was paid or completed.',
            ],
            [
                'term' => 'Average order value',
                'definition' => 'Order revenue divided by the number of orders, both measured over the same set of countable orders.',
            ],
            [
                'term' => 'Mixed colours',
                'definition' => 'A custom-mixed can is several lines (a base plus its tints). Its revenue is counted once for the whole mix and its quantity is the number of finished cans. Stock movement still counts each tint separately, because the tint is really consumed.',
            ],
            [
                'term' => 'As-of figures',
                'definition' => 'Stock levels, customer totals and open-order counts describe the moment the report was generated, not the reporting period. They are stamped with that timestamp wherever they appear.',
            ],
        ];
    }
}
