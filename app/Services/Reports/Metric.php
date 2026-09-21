<?php

namespace App\Services\Reports;

/**
 * One group of report figures.
 *
 * A subclass writes compute() once, for a single window. Running it again for
 * the comparison window and working out the deltas happens here, which is what
 * makes comparison available on every table rather than only on the four KPI
 * cards it used to reach.
 *
 * Results are memoised per instance: a section rendered on screen and the same
 * section rendered onto paper share one instance and therefore one set of
 * queries and one set of numbers.
 */
abstract class Metric
{
    private ?array $memo = null;

    /**
     * Whether these figures describe the range or the present moment. A
     * point-in-time metric is never compared against a past window — there is
     * only one "now" — and it carries an as-of stamp so a printed report
     * cannot imply that today's stock level was last month's.
     */
    protected string $temporality = ReportMeasure::PERIOD;

    /**
     * Scalar keys in the computed array that get a delta worked out
     * automatically.
     *
     * @var array<int, string>
     */
    protected array $deltaKeys = [];

    /**
     * @param  array<string, mixed>  $options  report-wide options (row limits
     *                                         and the like), passed to every
     *                                         metric so the builder never has
     *                                         to know which ones care.
     */
    public function __construct(
        protected readonly ReportPeriod $period,
        protected readonly array $options = [],
    ) {}

    /** How many rows a ranked table should show. */
    protected function topN(int $default): int
    {
        return max(1, (int) ($this->options['top_n'] ?? $default));
    }

    /** Stable identifier, used by sections and by the built payload. */
    abstract public static function key(): string;

    /**
     * Compute the figures for ONE window. Never reference $this->period here —
     * the comparison run passes a different period in.
     */
    abstract protected function compute(ReportPeriod $period): array;

    public function temporality(): string
    {
        return $this->temporality;
    }

    /**
     * @return array{
     *     key: string,
     *     temporality: string,
     *     as_of: ?string,
     *     current: array<string, mixed>,
     *     previous: ?array<string, mixed>,
     *     deltas: array<string, array<string, mixed>>
     * }
     */
    public function get(): array
    {
        return $this->memo ??= $this->build();
    }

    private function build(): array
    {
        $current = $this->compute($this->period);

        $comparison = $this->temporality === ReportMeasure::PERIOD
            ? $this->period->comparisonPeriod()
            : null;

        $previous = $comparison ? $this->compute($comparison) : null;

        return [
            'key' => static::key(),
            'temporality' => $this->temporality,
            'as_of' => $this->temporality === ReportMeasure::POINT_IN_TIME
                ? now()->toIso8601String()
                : null,
            'current' => $current,
            'previous' => $previous,
            'deltas' => $previous === null ? [] : $this->deltas($current, $previous),
        ];
    }

    private function deltas(array $current, array $previous): array
    {
        $deltas = [];

        foreach ($this->deltaKeys as $key) {
            $deltas[$key] = self::delta(
                (float) ($current[$key] ?? 0),
                (float) ($previous[$key] ?? 0),
            );
        }

        return $deltas;
    }

    /**
     * A change between two figures.
     *
     * `percent` is null when the previous figure was zero rather than 100.
     * Growth from nothing has no percentage, and the old page's "+100%" read
     * as a doubling to anyone who did not know the rule. The UI renders that
     * null as "new" instead.
     */
    public static function delta(float $current, float $previous): array
    {
        $change = $current - $previous;

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => $change,
            'percent' => $previous == 0.0 ? null : round(($change / abs($previous)) * 100, 1),
            'direction' => $change <=> 0,
        ];
    }
}
