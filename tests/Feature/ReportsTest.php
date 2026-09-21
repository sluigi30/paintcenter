<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReportPreset;
use App\Models\User;
use App\Services\Reports\Metrics\ColorMetrics;
use App\Services\Reports\Metrics\ProductMetrics;
use App\Services\Reports\Metrics\SalesMetrics;
use App\Services\Reports\PresetPayload;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportCharts;
use App\Services\Reports\ReportExport;
use App\Services\Reports\ReportMeasure;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ReportRange;
use App\Support\Reports\ReportSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reports rebuild.
 *
 * Three kinds of assertion here, and the last two matter as much as the first:
 *
 *   1. The figures are right.
 *   2. The figures RECONCILE — order revenue, line revenue, product revenue and
 *      colour revenue all describe the same money, so they must agree. The old
 *      page shipped a wrong average order value precisely because nothing
 *      checked that two of its queries meant the same thing by "order".
 *   3. The screen and the printed document show the SAME figures, from one
 *      metric result. That is what stops the duplicated reporting logic this
 *      refactor removed from growing back.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private const VERSIONED = PresetPayload::VERSION;

    /** Memoised: several tests act as the admin more than once. */
    private function admin(): User
    {
        return User::firstOrCreate([
            'email' => 'reports-admin@example.test',
        ], [
            'first_name' => 'Report',
            'last_name' => 'Admin',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    private function customer(string $email = 'buyer@example.test'): User
    {
        return User::firstOrCreate(['email' => $email], [
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'password' => bcrypt('password'),
            'role' => 'customer',
        ]);
    }

    private function product(): Product
    {
        $product = Product::create([
            'brand_id' => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name' => 'Testbrand Latex Colors',
            'description' => 'Water-based latex.',
        ]);

        $product->categories()->attach(Category::create(['category_name' => 'Latex Paint'])->id);

        return $product;
    }

    /**
     * The shade every plain order buys. Made once per test: the variant
     * identity index spans (product, colour, size, base), so a second identical
     * row is a constraint violation, not a second can.
     */
    private function variant(Product $product): ProductVariant
    {
        return $product->variants()->firstOrCreate(
            [
                'color_code' => 'B-1408',
                'color_name' => 'Burnt Sienna',
                'size_volume' => '4L',
            ],
            ['hex_code' => '#8A3324', 'price' => 1400, 'stock' => 99],
        );
    }

    /** An order with one plain line. */
    private function order(User $customer, Product $product, float $amount, string $status = 'completed', ?\DateTimeInterface $at = null): Order
    {
        $at ??= now();
        $variant = $this->variant($product);

        $order = Order::create([
            'user_id' => $customer->id,
            'order_date' => $at,
            'order_type' => 'pickup',
            'status' => $status,
            'total_amount' => $amount,
        ]);

        $order->forceFill(['created_at' => $at])->save();

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'color_code' => 'B-1408',
            'color_name' => 'Burnt Sienna',
            'hex_code' => '#8A3324',
            'size_volume' => '4L',
            'quantity' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
        ]);

        Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'gcash',
            'payment_status' => 'paid',
        ]);

        return $order;
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::make(now()->subDays(29)->toDateString(), now()->toDateString());
    }

    // -- Arithmetic ----------------------------------------

    /**
     * The bug the old page shipped: revenue excluded cancelled orders, the
     * order COUNT did not, and average order value divided one by the other.
     */
    public function test_average_order_value_uses_the_same_orders_as_revenue(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $this->order($customer, $product, 1000);
        $this->order($customer, $product, 3000);
        $this->order($customer, $product, 9999, 'cancelled');

        $sales = (new SalesMetrics($this->period()))->get()['current'];

        $this->assertSame(4000.0, $sales['revenue']);
        $this->assertSame(2, $sales['orders'], 'the cancelled order must not be counted');
        $this->assertSame(2000.0, $sales['avg_order_value']);
    }

    public function test_order_revenue_reconciles_with_line_revenue(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $this->order($customer, $product, 1500);
        $this->order($customer, $product, 2500);
        $this->order($customer, $product, 8000, 'cancelled');

        $period = $this->period();

        $this->assertSame(
            ReportMeasure::orderRevenue($period),
            ReportMeasure::lineRevenue($period),
            'order revenue and line revenue describe the same money; if this fails, an order-level charge was added and every attribution report needs a decision',
        );
    }

    public function test_product_and_colour_revenue_both_reconcile(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $this->order($customer, $product, 1200);
        $this->order($customer, $product, 800);

        $period = $this->period();
        $line = ReportMeasure::lineRevenue($period);

        $products = (new ProductMetrics($period))->get()['current'];
        $colors = (new ColorMetrics($period))->get()['current'];

        $this->assertSame($line, $products['revenue']);
        $this->assertSame($line, round($colors['factory_revenue'] + $colors['custom_revenue'], 2));
    }

    // -- Mixed cans ----------------------------------------

    /**
     * The roll-up rule: a mixed can is one thing the customer bought, however
     * many lines it took to record it.
     */
    public function test_a_mixed_can_is_counted_once_at_the_mix_colour(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $base = $product->variants()->create([
            'color_name' => 'Neutral Base', 'size_volume' => '4L', 'price' => 1000, 'stock' => 5,
        ]);
        $tint = $product->variants()->create([
            'color_code' => 'T-02', 'color_name' => 'Green Colourant', 'size_volume' => '1L', 'price' => 75, 'stock' => 20,
        ]);

        $order = Order::create([
            'user_id' => $customer->id,
            'order_date' => now(),
            'order_type' => 'pickup',
            'status' => 'completed',
            'total_amount' => 1150,
        ]);

        $mix = ['mix_group' => 'mix-uuid-1', 'custom_hex' => '#4F7942', 'custom_color_name' => 'Fern'];

        OrderItem::create($mix + [
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $base->id,
            'mix_role' => 'base', 'mix_liters' => 4, 'size_volume' => '4L',
            'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000,
        ]);

        OrderItem::create($mix + [
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $tint->id,
            'mix_role' => 'tint', 'mix_liters' => 1, 'size_volume' => '1L',
            'color_code' => 'T-02', 'color_name' => 'Green Colourant',
            'quantity' => 2, 'unit_price' => 75, 'subtotal' => 150,
        ]);

        $colors = (new ColorMetrics($this->period()))->get()['current'];

        $this->assertCount(1, $colors['custom'], 'the mix is one row, not one per component');
        $this->assertSame('Fern', $colors['custom'][0]['label']);
        $this->assertSame(1150.0, $colors['custom'][0]['revenue'], 'revenue is the whole group, counted once');
        $this->assertSame(1, $colors['custom'][0]['quantity'], 'quantity is the base line: two pints of tint are not two cans');

        // The colourant must NOT surface as a factory shade of its own.
        $this->assertEmpty(
            array_filter($colors['factory'], fn ($row) => $row['code'] === 'T-02'),
            'a tint poured into a mix is not a shade the customer bought',
        );

        // And the roll-up must not lose or duplicate money.
        $this->assertSame(
            ReportMeasure::lineRevenue($this->period()),
            round($colors['factory_revenue'] + $colors['custom_revenue'], 2),
        );
    }

    /** Products do NOT roll up: the tint really left the shelf. */
    public function test_products_count_mix_components_separately(): void
    {
        $this->test_a_mixed_can_is_counted_once_at_the_mix_colour();

        $products = (new ProductMetrics($this->period()))->get()['current'];

        $this->assertSame(ReportMeasure::lineRevenue($this->period()), $products['revenue']);
        $this->assertSame(3, $products['units'], 'one base can plus two tint pints');
    }

    // -- Period behaviour ----------------------------------

    public function test_an_explicit_granularity_is_never_overridden(): void
    {
        $period = ReportPeriod::make('2026-01-01', '2026-12-31');

        $this->assertSame(ReportPeriod::MONTH, $period->granularity());
        $this->assertFalse($period->isGranularityExplicit());

        $daily = $period->withGranularity(ReportPeriod::DAY);

        $this->assertSame(ReportPeriod::DAY, $daily->granularity());
        $this->assertTrue($daily->isGranularityExplicit());
        $this->assertGreaterThan(360, count($daily->buckets()));

        // Immutability: the original is untouched, so the metrics sharing it
        // cannot have their grouping changed underneath them.
        $this->assertSame(ReportPeriod::MONTH, $period->granularity());
    }

    public function test_the_comparison_window_keeps_the_same_grouping(): void
    {
        $period = ReportPeriod::make('2026-03-01', '2026-03-31', ReportPeriod::COMPARE_YEAR)
            ->withGranularity(ReportPeriod::DAY);

        $this->assertSame(ReportPeriod::DAY, $period->comparisonPeriod()->granularity());
    }

    public function test_point_in_time_metrics_are_not_compared(): void
    {
        $period = ReportPeriod::make(
            now()->subDays(29)->toDateString(),
            now()->toDateString(),
            ReportPeriod::COMPARE_PREVIOUS,
        );

        $inventory = ReportBuilder::make($period, ['inventory'])->build()['sections'][0]['data']['inventory'];

        $this->assertSame(ReportMeasure::POINT_IN_TIME, $inventory['temporality']);
        $this->assertNull($inventory['previous'], 'there is only one "now" to compare against');
        $this->assertNotNull($inventory['as_of'], 'the paper must be able to stamp it');
    }

    // -- Comparison charts ---------------------------------

    /**
     * Overlaying two periods plots the comparison against the CURRENT period's
     * axis labels. Split gives each its own, which is the point.
     */
    public function test_a_split_comparison_gives_each_period_its_own_axis(): void
    {
        $this->order($this->customer(), $this->product(), 1000);

        $period = ReportPeriod::make('2026-03-01', '2026-03-31', ReportPeriod::COMPARE_PREVIOUS);
        $built = ReportBuilder::make($period, ['sales_trend'], ['comparison_layout' => ReportCharts::LAYOUT_SPLIT])->build();

        $revenue = $built['charts']['sales_trend']['groups'][0];

        $this->assertCount(2, $revenue['charts'], 'a split comparison is two charts');

        [$current, $comparison] = $revenue['charts'];

        $this->assertNotSame(
            $current['spec']['labels'],
            $comparison['spec']['labels'],
            'each chart must carry its own dates, not those of the current period',
        );

        $this->assertStringContainsString('Mar', $current['title']);
        $this->assertStringContainsString('Feb', $comparison['title']);
    }

    /**
     * Without a shared ceiling, two auto-scaled charts draw a halved period
     * identically to a doubled one - which is worse than the overlay.
     */
    public function test_a_split_pair_is_locked_to_one_scale(): void
    {
        $this->order($this->customer(), $this->product(), 1000);

        $period = ReportPeriod::make('2026-03-01', '2026-03-31', ReportPeriod::COMPARE_PREVIOUS);
        $built = ReportBuilder::make($period, ['sales_trend'], ['comparison_layout' => ReportCharts::LAYOUT_SPLIT])->build();

        $revenue = $built['charts']['sales_trend']['groups'][0];

        $this->assertTrue($revenue['shared_scale']);
        $this->assertSame(
            $revenue['charts'][0]['spec']['y_max'],
            $revenue['charts'][1]['spec']['y_max'],
        );
    }

    public function test_the_overlaid_layout_is_still_available(): void
    {
        $this->order($this->customer(), $this->product(), 1000);

        $period = ReportPeriod::make('2026-03-01', '2026-03-31', ReportPeriod::COMPARE_PREVIOUS);
        $built = ReportBuilder::make($period, ['sales_trend'], ['comparison_layout' => ReportCharts::LAYOUT_OVERLAY])->build();

        $revenue = $built['charts']['sales_trend']['groups'][0];

        $this->assertCount(1, $revenue['charts']);
        $this->assertCount(2, $revenue['charts'][0]['spec']['series']);
    }

    /**
     * The stale-chart bug: the canvases sit behind wire:ignore, so their
     * data-chart attributes never update and re-rendering from the DOM redrew
     * the PREVIOUS grouping. The specs must travel in the event instead.
     */
    public function test_changing_the_grouping_pushes_new_chart_specs(): void
    {
        $this->order($this->customer(), $this->product(), 1000);

        $component = Livewire::actingAs($this->admin())
            ->test(Reports::class, [
                'from' => '2026-01-01',
                'to' => '2026-06-30',
                'sections' => ['sales_trend'],
            ]);

        $component->set('granularity', ReportPeriod::MONTH)
            ->assertDispatched('rpt:charts', function (string $event, array $params) {
                return count($params['specs']['rptRevenue']['labels']) === 6;
            });

        $component->set('granularity', ReportPeriod::DAY)
            ->assertDispatched('rpt:charts', function (string $event, array $params) {
                return count($params['specs']['rptRevenue']['labels']) > 150;
            });
    }

    /**
     * A count axis topped out at "7.5600000000000005" - the data maximum times
     * a headroom factor, in binary floating point, on a scale counting orders.
     * Both halves were wrong: the residue, and the idea of 7.56 orders.
     */
    public function test_a_count_axis_never_gets_a_fractional_ceiling(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        // Seven orders in one bucket, so the orders series peaks at 7.
        for ($i = 0; $i < 7; $i++) {
            $this->order($customer, $product, 100, 'completed', now()->subDays(3));
        }

        $period = ReportPeriod::make(
            now()->subMonths(4)->toDateString(),
            now()->toDateString(),
            ReportPeriod::COMPARE_PREVIOUS,
        );

        $built = ReportBuilder::make($period, ['sales_trend'], [
            'comparison_layout' => ReportCharts::LAYOUT_SPLIT,
        ])->build();

        $groups = collect($built['charts']['sales_trend']['groups']);
        $orders = $groups->firstWhere('caption', 'Orders');
        $ceiling = $orders['charts'][0]['spec']['y_max'];

        $this->assertSame(8.0, $ceiling, 'seven orders must round to a whole 8, not 7.5600000000000005');
        $this->assertSame(
            $ceiling,
            $orders['charts'][1]['spec']['y_max'],
            'and the pair must still share it',
        );

        // Money may be fractional, but never carries float residue.
        $revenue = $groups->firstWhere('caption', 'Revenue');
        $this->assertSame(
            round($revenue['charts'][0]['spec']['y_max'], 2),
            $revenue['charts'][0]['spec']['y_max'],
        );
    }

    // -- Saved presets -------------------------------------

    /**
     * The point of a preset: it stores the RULE for the dates, not the dates.
     * "Monthly Owner Report" opened in November must report November.
     */
    public function test_a_rolling_range_is_resaved_as_a_rule_and_resolved_on_open(): void
    {
        $payload = PresetPayload::capture([
            'from' => now()->subDays(29)->toDateString(),
            'to' => now()->toDateString(),
            'sections' => ['summary'],
        ]);

        $this->assertSame('rolling', $payload['range']['mode']);
        $this->assertSame('last_30_days', $payload['range']['key']);
        $this->assertArrayNotHasKey('from', $payload['range'], 'a rolling preset must not pin dates');

        // Opened a week later, it still means "the last 30 days".
        $this->travel(7)->days();

        $state = PresetPayload::resolve($payload);

        $this->assertSame(now()->toDateString(), $state['to']);
        $this->assertSame(now()->subDays(29)->toDateString(), $state['from']);

        $this->travelBack();
    }

    /** A one-off range has no rule, so it saves as fixed dates. */
    public function test_a_range_matching_no_rule_is_saved_as_fixed_dates(): void
    {
        $payload = PresetPayload::capture([
            'from' => '2026-03-02',
            'to' => '2026-04-07',
            'sections' => ['summary'],
        ]);

        $this->assertSame(ReportRange::FIXED, $payload['range']['mode']);
        $this->assertSame('2026-03-02', $payload['range']['from']);

        $this->assertSame('2026-03-02', PresetPayload::resolve($payload)['from']);
    }

    /**
     * Configuration only — a preset must never carry a figure.
     *
     * Asserted as an exact key whitelist rather than by hunting for words: the
     * config vocabulary legitimately contains terms like "previous" (a
     * comparison mode) and "order_breakdown" (a section), so word-matching
     * reports leaks that are not leaks.
     */
    public function test_a_preset_stores_configuration_and_nothing_else(): void
    {
        $payload = PresetPayload::capture([
            'from' => now()->subDays(29)->toDateString(),
            'to' => now()->toDateString(),
            'sections' => ['summary', 'colors'],
            'top_n' => 15,
        ]);

        $this->assertSame([
            'v',
            'range',
            'sections',
            'granularity',
            'compare_mode',
            'compare_from',
            'compare_to',
            'comparison_layout',
            'top_n',
        ], array_keys($payload));

        // The keys a built report carries must appear nowhere inside it.
        foreach (['current', 'deltas', 'series', 'generated_at', 'definitions'] as $reportKey) {
            $this->assertArrayNotHasKey($reportKey, $payload);
        }

        $this->assertSame(self::VERSIONED, $payload['v']);
    }

    /** An old preset naming a section that no longer exists still opens. */
    public function test_a_preset_survives_a_renamed_section(): void
    {
        $state = PresetPayload::resolve([
            'v' => 1,
            'range' => ['mode' => 'rolling', 'key' => 'last_30_days'],
            'sections' => ['summary', 'a_section_that_was_renamed'],
        ]);

        $this->assertSame(['summary'], $state['sections']);
    }

    public function test_an_admin_can_save_and_reopen_a_report(): void
    {
        $this->order($this->customer(), $this->product(), 500);

        $component = Livewire::actingAs($this->admin())
            ->test(Reports::class)
            ->set('sections', ['summary', 'colors'])
            ->set('topN', 15)
            ->set('granularity', ReportPeriod::MONTH)
            ->set('presetName', 'Monthly Owner Report')
            ->call('savePreset');

        $preset = ReportPreset::firstWhere('name', 'Monthly Owner Report');
        $this->assertNotNull($preset);
        $this->assertSame(['summary', 'colors'], $preset->payload['sections']);

        // Change everything, then reopen the preset.
        $component->set('sections', ['inventory'])
            ->set('topN', 5)
            ->set('granularity', null)
            ->call('applyPreset', $preset->id)
            ->assertSet('sections', ['summary', 'colors'])
            ->assertSet('topN', 15)
            ->assertSet('granularity', ReportPeriod::MONTH);
    }

    public function test_saving_under_an_existing_name_updates_it(): void
    {
        $admin = $this->admin();

        foreach ([['summary'], ['inventory']] as $sections) {
            Livewire::actingAs($admin)
                ->test(Reports::class)
                ->set('sections', $sections)
                ->set('presetName', 'Weekly')
                ->call('savePreset');
        }

        $this->assertSame(1, ReportPreset::where('name', 'Weekly')->count());
        $this->assertSame(['inventory'], ReportPreset::firstWhere('name', 'Weekly')->payload['sections']);
    }

    /** One admin must never be able to open or delete another's preset. */
    public function test_presets_are_private_to_the_admin_who_saved_them(): void
    {
        $owner = $this->admin();

        $other = User::create([
            'first_name' => 'Other', 'last_name' => 'Admin',
            'email' => 'other-admin@example.test',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);

        $preset = ReportPreset::create([
            'user_id' => $owner->id,
            'name' => 'Private',
            'payload' => PresetPayload::capture(['from' => '2026-01-01', 'to' => '2026-01-31', 'sections' => ['inventory']]),
        ]);

        Livewire::actingAs($other)
            ->test(Reports::class)
            ->call('applyPreset', $preset->id)
            ->assertSet('sections', ReportSections::defaultKeys());

        Livewire::actingAs($other)
            ->test(Reports::class)
            ->call('deletePreset', $preset->id);

        $this->assertDatabaseHas('report_presets', ['id' => $preset->id]);
    }

    // -- CSV export ----------------------------------------

    /** A CSV exists to be summed, so the numbers must arrive as numbers. */
    public function test_the_export_carries_raw_numbers_not_formatted_strings(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $this->order($customer, $product, 1750);
        $this->order($customer, $product, 3250);

        $csv = $this->csv('summary');

        $this->assertStringContainsString('5000', $csv, 'revenue must be a plain number');
        $this->assertStringNotContainsString('5,000', $csv, 'no thousands separators');
        $this->assertStringNotContainsString("\u{20B1}", $csv, 'no currency symbol in a data column');
    }

    /** The file must agree with the report it came from. */
    public function test_the_export_matches_the_rendered_report(): void
    {
        $this->order($this->customer(), $this->product(), 2400);

        $screen = $this->actingAs($this->admin())
            ->get('/admin/reports?'.$this->exportQuery().'&sections=summary')
            ->assertOk()->getContent();

        $this->assertStringContainsString("\u{20B1}2,400.00", $screen);
        $this->assertStringContainsString('2400', $this->csv('summary'));
    }

    /** Two sub-tables export as one file, told apart by a group column. */
    public function test_a_two_table_section_exports_as_one_grouped_file(): void
    {
        $this->order($this->customer(), $this->product(), 900);

        $csv = $this->csv('colors');
        $lines = array_values(array_filter(explode(chr(10), trim($csv))));

        $this->assertStringStartsWith("\u{FEFF}Group,Colour", $lines[0], 'BOM, then a Group column');
        $this->assertStringContainsString('Factory shade', $csv);
    }

    public function test_sections_with_nothing_tabular_are_not_exportable(): void
    {
        $this->assertFalse(ReportExport::isExportable('definitions'));
        $this->assertFalse(ReportExport::isExportable('signatories'));
        $this->assertTrue(ReportExport::isExportable('top_products'));

        $this->actingAs($this->admin())
            ->get('/admin/reports/export/definitions?'.$this->exportQuery())
            ->assertNotFound();
    }

    public function test_the_export_is_admin_only(): void
    {
        $this->actingAs($this->customer())
            ->get('/admin/reports/export/summary?'.$this->exportQuery())
            ->assertForbidden();
    }

    private function exportQuery(): string
    {
        return 'from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString();
    }

    private function csv(string $section): string
    {
        return $this->actingAs($this->admin())
            ->get("/admin/reports/export/{$section}?".$this->exportQuery())
            ->assertOk()
            ->streamedContent();
    }

    // -- Quarter and year grouping -------------------------

    public function test_quarter_and_year_bucket_the_range_correctly(): void
    {
        $quarters = ReportPeriod::make('2026-01-01', '2026-12-31')
            ->withGranularity(ReportPeriod::QUARTER)
            ->buckets();

        $this->assertSame(
            ['Q1 2026', 'Q2 2026', 'Q3 2026', 'Q4 2026'],
            array_column($quarters, 'label'),
        );

        $years = ReportPeriod::make('2024-01-01', '2026-12-31')
            ->withGranularity(ReportPeriod::YEAR)
            ->buckets();

        $this->assertSame(['2024', '2025', '2026'], array_column($years, 'label'));
    }

    /**
     * Without the coarse steps every long range fell to monthly, so a decade
     * drew 120 points and two decades drew 240.
     */
    public function test_automatic_grouping_escalates_on_long_ranges(): void
    {
        foreach ([
            ['2026-09-01', '2026-09-20', ReportPeriod::DAY],
            ['2026-04-01', '2026-09-20', ReportPeriod::WEEK],
            ['2024-01-01', '2026-12-31', ReportPeriod::MONTH],
            ['2018-01-01', '2026-12-31', ReportPeriod::QUARTER],
            ['2005-01-01', '2026-12-31', ReportPeriod::YEAR],
        ] as [$from, $to, $expected]) {
            $period = ReportPeriod::make($from, $to);

            $this->assertSame($expected, $period->granularity(), "{$from} to {$to}");
            $this->assertLessThanOrEqual(
                ReportPeriod::BUCKET_WARNING_THRESHOLD,
                count($period->buckets()),
                "automatic grouping should not exceed the readable point count for {$from} to {$to}",
            );
        }
    }

    /**
     * A range starting in February, grouped by year, renders one bucket
     * labelled "2026" that is not a year of trading. The report has to say so.
     */
    public function test_a_partial_first_or_last_bucket_is_flagged(): void
    {
        $partial = ReportPeriod::make('2026-02-01', '2026-11-30')->withGranularity(ReportPeriod::YEAR);
        $whole = ReportPeriod::make('2026-01-01', '2026-12-31')->withGranularity(ReportPeriod::YEAR);

        $this->assertTrue($partial->hasPartialBuckets());
        $this->assertFalse($whole->hasPartialBuckets());

        $this->assertTrue(
            ReportBuilder::make($partial, ['sales_trend'])->build()['period']['partial_buckets'],
        );
    }

    /** A line between two marks with no trend between them is the wrong form. */
    public function test_few_points_are_drawn_as_bars_not_a_line(): void
    {
        $this->order($this->customer(), $this->product(), 1200);

        $annual = ReportBuilder::make(
            ReportPeriod::make('2026-01-01', '2026-12-31')->withGranularity(ReportPeriod::YEAR),
            ['sales_trend'],
        )->build();

        $this->assertSame('bar', $annual['charts']['sales_trend']['groups'][0]['charts'][0]['spec']['type']);

        $monthly = ReportBuilder::make(
            ReportPeriod::make('2026-01-01', '2026-12-31')->withGranularity(ReportPeriod::MONTH),
            ['sales_trend'],
        )->build();

        $this->assertSame('line', $monthly['charts']['sales_trend']['groups'][0]['charts'][0]['spec']['type']);
    }

    /** A preset saved at quarterly must still open at quarterly. */
    public function test_the_new_granularities_round_trip_through_a_preset(): void
    {
        foreach ([ReportPeriod::QUARTER, ReportPeriod::YEAR] as $granularity) {
            $payload = PresetPayload::capture([
                'from' => now()->subDays(29)->toDateString(),
                'to' => now()->toDateString(),
                'sections' => ['summary'],
                'granularity' => $granularity,
            ]);

            $this->assertSame($granularity, PresetPayload::resolve($payload)['granularity']);
        }
    }

    /**
     * A grouping coarser than the range collapses it to one bucket. The figures
     * are right, but two such groupings look identical (a 30-day range by
     * quarter and by year are both one bar of the same height), which reads as
     * the page having ignored the choice. It has to say so.
     */
    public function test_a_grouping_that_collapses_the_range_is_flagged(): void
    {
        $this->order($this->customer(), $this->product(), 1500);

        $range = ['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString()];

        foreach ([ReportPeriod::QUARTER, ReportPeriod::YEAR] as $granularity) {
            $period = ReportPeriod::make($range['from'], $range['to'])->withGranularity($granularity);

            $this->assertTrue($period->collapsesToSingleBucket(), $granularity);
            $this->assertNotNull($period->finerGranularity(), 'a finer grouping must be suggestable');

            $payload = ReportBuilder::make($period, ['sales_trend'])->build();
            $this->assertTrue($payload['period']['single_bucket']);
        }

        // Daily over the same range is not collapsed.
        $daily = ReportPeriod::make($range['from'], $range['to'])->withGranularity(ReportPeriod::DAY);
        $this->assertFalse($daily->collapsesToSingleBucket());
        $this->assertNull($daily->finerGranularity(), 'day is already the finest');

        // And the admin is told, on screen.
        $html = $this->actingAs($this->admin())
            ->get('/admin/reports?from='.$range['from'].'&to='.$range['to'].'&granularity=year&sections=sales_trend')
            ->assertOk()->getContent();

        $this->assertStringContainsString('falls inside a single year', $html);
    }

    // -- Rendering -----------------------------------------

    public function test_every_section_renders_on_screen_and_on_paper(): void
    {
        $this->order($this->customer(), $this->product(), 2400);

        $keys = implode(',', array_keys(ReportSections::all()));
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/reports?sections='.$keys)->assertOk();
        $this->actingAs($admin)->get('/admin/reports/print?sections='.$keys)->assertOk();
    }

    /**
     * The guarantee the whole rebuild rests on: one metric result behind both
     * outputs. Same inputs in, the same figure out of each renderer.
     */
    public function test_the_screen_and_the_printed_report_show_the_same_figures(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $this->order($customer, $product, 1750);
        $this->order($customer, $product, 3250);
        $this->order($customer, $product, 999, 'cancelled');

        $query = 'from='.now()->subDays(29)->toDateString()
            .'&to='.now()->toDateString()
            .'&sections=summary,top_products,colors';

        $admin = $this->admin();

        $screen = $this->actingAs($admin)->get('/admin/reports?'.$query)->assertOk()->getContent();
        $paper = $this->actingAs($admin)->get('/admin/reports/print?'.$query)->assertOk()->getContent();

        // Revenue, order count and average order value, rendered.
        foreach (['₱5,000.00', '₱2,500.00'] as $figure) {
            $this->assertStringContainsString($figure, $screen, "screen is missing {$figure}");
            $this->assertStringContainsString($figure, $paper, "paper is missing {$figure}");
        }
    }

    /**
     * Query logic must not creep back into the templates. This is the guard
     * that keeps the two renderers sharing one source rather than growing a
     * second one — which is exactly how the page this replaced got its
     * duplicated print markup.
     */
    public function test_report_templates_contain_no_query_logic(): void
    {
        $offenders = [];

        foreach ([
            resource_path('views/reports'),
            resource_path('views/components/rpt'),
        ] as $directory) {
            foreach (glob($directory.'/**/*.blade.php') ?: [] as $file) {
                $contents = file_get_contents($file);

                foreach (['DB::', '::query(', '->get()', 'whereBetween', 'App\\Models'] as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = basename($file).' uses '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'report partials must read arrays, never query');
    }

    public function test_the_printed_report_is_admin_only(): void
    {
        $this->get('/admin/reports/print')->assertRedirect();
        $this->actingAs($this->customer())->get('/admin/reports/print')->assertForbidden();
    }
}
