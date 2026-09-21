<?php

namespace App\Services\Reports;

use App\Support\Reports\ReportSection;
use App\Support\Reports\ReportSections;

/**
 * Turns a period plus a set of chosen sections into the single payload that
 * BOTH renderers consume.
 *
 * This is the structural half of the guarantee that the screen and the paper
 * can never disagree. Neither the Livewire page nor the print route computes
 * anything: each builds one of these, calls build(), and hands the result to
 * partials that only read arrays. There is no second query path to drift.
 *
 * Metric instances are shared and memoised across sections, so the two sections
 * that both read SalesMetrics run its queries once and necessarily show the
 * same numbers — and a section nobody ticked runs nothing at all. The page this
 * replaces ran roughly fifteen queries on every keystroke of a date input,
 * whether or not anyone was looking at the answers.
 */
final class ReportBuilder
{
    /** @var array<class-string<Metric>, Metric> */
    private array $instances = [];

    private ?array $payload = null;

    /**
     * @param  array<int, string>  $sectionKeys
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly ReportPeriod $period,
        private readonly array $sectionKeys,
        private readonly array $options = [],
    ) {}

    /**
     * @param  array<int, string>|null  $sectionKeys  null means the defaults
     * @param  array<string, mixed>  $options
     */
    public static function make(ReportPeriod $period, ?array $sectionKeys = null, array $options = []): self
    {
        return new self($period, $sectionKeys ?? ReportSections::defaultKeys(), $options);
    }

    public function period(): ReportPeriod
    {
        return $this->period;
    }

    /** @return array<int, ReportSection> */
    public function sections(): array
    {
        return ReportSections::resolve($this->sectionKeys);
    }

    /**
     * The computed payload for one metric class. Shared across every section
     * that asks for it.
     *
     * @param  class-string<Metric>  $metric
     */
    public function metric(string $metric): array
    {
        $instance = $this->instances[$metric] ??= new $metric($this->period, $this->options);

        return $instance->get();
    }

    /**
     * @return array{
     *     period: array<string, mixed>,
     *     options: array<string, mixed>,
     *     generated_at: string,
     *     sections: array<int, array<string, mixed>>,
     *     definitions: array<int, array{term: string, definition: string}>
     * }
     */
    public function build(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        $period = $this->periodPayload();

        $sections = array_map(
            fn (ReportSection $section) => $section->toArray() + [
                'data' => collect($section->metrics)
                    ->mapWithKeys(fn (string $metric) => [$metric::key() => $this->metric($metric)])
                    ->all(),
            ],
            $this->sections(),
        );

        return $this->payload = [
            'period' => $period,
            'options' => $this->options,
            'generated_at' => now()->toIso8601String(),
            'sections' => $sections,

            // Built here rather than in the templates so the page can hand the
            // new specs to the browser when anything changes — a canvas behind
            // wire:ignore never receives an updated data attribute.
            'charts' => ReportCharts::forReport(
                $sections,
                $period,
                $this->options['comparison_layout'] ?? ReportCharts::LAYOUT_SPLIT,
            ),

            'definitions' => ReportMeasure::definitions(),
        ];
    }

    private function periodPayload(): array
    {
        $comparison = $this->period->comparisonPeriod();

        return $this->period->toArray() + [
            'label' => $this->period->label(),
            'day_count' => $this->period->dayCount(),
            'granularity_resolved' => $this->period->granularity(),
            'granularity_label' => $this->period->granularityLabel(),
            'granularity_explicit' => $this->period->isGranularityExplicit(),
            'bucket_count' => count($this->period->buckets()),
            'bucket_warning' => $this->period->exceedsBucketWarning(),
            'partial_buckets' => $this->period->hasPartialBuckets(),
            'single_bucket' => $this->period->collapsesToSingleBucket(),
            'finer_granularity' => $this->period->finerGranularity(),
            'compare_mode_label' => $this->period->compareModeLabel(),
            'compare_label' => $comparison?->label(),
            'has_comparison' => $comparison !== null,
        ];
    }
}
