<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The reporting window, and everything derived from it.
 *
 * IMMUTABLE ON PURPOSE. One period object is handed to every metric class in a
 * run; if any of them could mutate it, the rest would silently change shape
 * underneath. Every "setter" returns a new instance instead.
 *
 * Granularity is the part worth reading twice. It resolves automatically from
 * the span, but an explicit choice always wins and is never quietly replaced —
 * a 31-day range once grouped monthly and drew the whole month as a single dot
 * (CONTEXT.md section 9), which is what happens when the system decides this
 * silently. The UI shows which grouping is in effect and lets it be overridden.
 */
final class ReportPeriod
{
    // -- Comparison modes ----------------------------------
    public const COMPARE_NONE = 'none';

    public const COMPARE_PREVIOUS = 'previous';

    public const COMPARE_YEAR = 'year_over_year';

    public const COMPARE_CUSTOM = 'custom';

    public const COMPARE_MODES = [
        self::COMPARE_NONE => 'No comparison',
        self::COMPARE_PREVIOUS => 'Previous period',
        self::COMPARE_YEAR => 'Same period last year',
        self::COMPARE_CUSTOM => 'Custom period',
    ];

    // -- Granularity ---------------------------------------
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public const QUARTER = 'quarter';

    public const YEAR = 'year';

    /** Order matters: the toolbar lists them coarsest-last. */
    public const GRANULARITIES = [
        self::DAY => 'Day',
        self::WEEK => 'Week',
        self::MONTH => 'Month',
        self::QUARTER => 'Quarter',
        self::YEAR => 'Year',
    ];

    /**
     * Past this many buckets a line chart stops being readable. This WARNS in
     * the UI; it never overrides the choice — overriding silently is the bug
     * this class exists to prevent.
     */
    public const BUCKET_WARNING_THRESHOLD = 120;

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $compareMode,
        private readonly ?CarbonImmutable $customCompareFrom,
        private readonly ?CarbonImmutable $customCompareTo,
        private readonly ?string $explicitGranularity,
    ) {}

    public static function make(
        string|\DateTimeInterface $from,
        string|\DateTimeInterface $to,
        string $compareMode = self::COMPARE_NONE,
        string|\DateTimeInterface|null $compareFrom = null,
        string|\DateTimeInterface|null $compareTo = null,
        ?string $granularity = null,
    ): self {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();

        // A backwards range is a slipped date input, not an error worth
        // throwing at an admin mid-keystroke — swap and carry on.
        if ($end->lt($start)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return new self(
            $start,
            $end,
            array_key_exists($compareMode, self::COMPARE_MODES) ? $compareMode : self::COMPARE_NONE,
            $compareFrom ? CarbonImmutable::parse($compareFrom)->startOfDay() : null,
            $compareTo ? CarbonImmutable::parse($compareTo)->endOfDay() : null,
            self::normalizeGranularity($granularity),
        );
    }

    private static function normalizeGranularity(?string $granularity): ?string
    {
        return array_key_exists((string) $granularity, self::GRANULARITIES) ? $granularity : null;
    }

    // -- Immutable "setters" -------------------------------

    /** Pass null to hand grouping back to the automatic default. */
    public function withGranularity(?string $granularity): self
    {
        return new self(
            $this->from,
            $this->to,
            $this->compareMode,
            $this->customCompareFrom,
            $this->customCompareTo,
            self::normalizeGranularity($granularity),
        );
    }

    public function withCompare(
        string $mode,
        string|\DateTimeInterface|null $from = null,
        string|\DateTimeInterface|null $to = null,
    ): self {
        return new self(
            $this->from,
            $this->to,
            array_key_exists($mode, self::COMPARE_MODES) ? $mode : self::COMPARE_NONE,
            $from ? CarbonImmutable::parse($from)->startOfDay() : $this->customCompareFrom,
            $to ? CarbonImmutable::parse($to)->endOfDay() : $this->customCompareTo,
            $this->explicitGranularity,
        );
    }

    public function withDates(string|\DateTimeInterface $from, string|\DateTimeInterface $to): self
    {
        return self::make(
            $from,
            $to,
            $this->compareMode,
            $this->customCompareFrom,
            $this->customCompareTo,
            $this->explicitGranularity,
        );
    }

    // -- Granularity resolution ----------------------------

    /** The grouping actually in effect: the explicit choice, else the automatic one. */
    public function granularity(): string
    {
        return $this->explicitGranularity ?? $this->autoGranularity();
    }

    /**
     * Thresholds chosen to keep the point count readable at every span: three
     * years monthly is 36 points, ten years quarterly is 40. Without the two
     * coarse steps a long range fell to MONTH and drew hundreds of points.
     */
    public function autoGranularity(): string
    {
        return match (true) {
            $this->dayCount() <= 31 => self::DAY,
            $this->dayCount() <= 182 => self::WEEK,
            $this->dayCount() <= 1096 => self::MONTH,    // ~3 years
            $this->dayCount() <= 3653 => self::QUARTER,  // ~10 years
            default => self::YEAR,
        };
    }

    public function isGranularityExplicit(): bool
    {
        return $this->explicitGranularity !== null;
    }

    /** "Day" or "Auto (Day)" — so the toolbar can always say which is which. */
    public function granularityLabel(): string
    {
        $label = self::GRANULARITIES[$this->granularity()];

        return $this->isGranularityExplicit() ? $label : "Auto ({$label})";
    }

    /**
     * True when the first or last bucket reaches outside the range, so its
     * total covers only part of the period its label names.
     *
     * Harmless at day grouping and easy to miss at week; at quarter and year it
     * is a trap — a range starting in February renders one bucket labelled
     * "2026" holding eleven months of trading, which reads as a full year.
     */
    public function hasPartialBuckets(): bool
    {
        $buckets = $this->buckets();

        if ($buckets === []) {
            return false;
        }

        return $buckets[0]['start']->lt($this->from)
            || $buckets[count($buckets) - 1]['end']->gt($this->to);
    }

    /**
     * True when the whole range falls inside ONE bucket.
     *
     * The chart is then a single bar holding the period's total, with no trend
     * in it — and switching between two groupings that both collapse (a 30-day
     * range by quarter and by year) changes only the axis label, which reads as
     * the page having ignored the choice. There is a warning for too many
     * buckets; this is its mirror.
     */
    public function collapsesToSingleBucket(): bool
    {
        return count($this->buckets()) === 1;
    }

    /** The next finer grouping, to suggest when the range has collapsed. */
    public function finerGranularity(): ?string
    {
        $steps = array_keys(self::GRANULARITIES);
        $index = array_search($this->granularity(), $steps, true);

        return ($index === false || $index < 1) ? null : $steps[$index - 1];
    }

    public function exceedsBucketWarning(): bool
    {
        return count($this->buckets()) > self::BUCKET_WARNING_THRESHOLD;
    }

    // -- Span ----------------------------------------------

    /**
     * Whole days, inclusive. Computed off the bare DATES: Carbon 3 returns a
     * float when diffing against an endOfDay timestamp (30.99...), and letting
     * that reach a threshold check is what mis-grouped 31-day ranges before.
     */
    public function dayCount(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    public function hasComparison(): bool
    {
        return $this->comparisonPeriod() !== null;
    }

    /**
     * The window this one is measured against, as a period in its own right —
     * so every metric can simply run its own query again instead of carrying a
     * second set of "compare" branches.
     *
     * Two things are deliberate: the returned period compares against nothing
     * (so this never recurses), and it carries this period's RESOLVED
     * granularity explicitly. A year-over-year window can be a day longer or
     * shorter across a leap year, and left to resolve on its own it could pick
     * a different grouping and hand back a series that no longer lines up.
     */
    public function comparisonPeriod(): ?self
    {
        [$from, $to] = match ($this->compareMode) {
            self::COMPARE_PREVIOUS => [
                $this->from->subDays($this->dayCount()),
                $this->from->subDay(),
            ],
            self::COMPARE_YEAR => [
                $this->from->subYear(),
                $this->to->subYear(),
            ],
            self::COMPARE_CUSTOM => [
                $this->customCompareFrom,
                $this->customCompareTo,
            ],
            default => [null, null],
        };

        if (! $from || ! $to) {
            return null;
        }

        return new self(
            $from->startOfDay(),
            $to->endOfDay(),
            self::COMPARE_NONE,
            null,
            null,
            $this->granularity(),
        );
    }

    // -- Bucketing -----------------------------------------

    /**
     * The SQL expression that reduces a timestamp to a bare date.
     *
     * Every report groups by DAY in SQL and folds days into weeks or months in
     * PHP. That is one expression to keep portable instead of three formats
     * per driver, and it is why week grouping needs no driver branch at all —
     * MySQL's ISO weeks and SQLite's %W disagree about where a week starts.
     */
    public static function dayExpression(string $column = 'created_at'): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "CAST({$column} AS DATE)",
            default => "DATE({$column})", // MySQL and SQLite both accept DATE()
        };
    }

    /** The bucket a given moment falls in, as a stable key. */
    public function bucketKey(string|\DateTimeInterface $moment): string
    {
        $date = CarbonImmutable::parse($moment);

        return match ($this->granularity()) {
            self::DAY => $date->format('Y-m-d'),
            self::WEEK => $date->startOfWeek()->format('Y-m-d'),
            self::MONTH => $date->format('Y-m'),
            self::QUARTER => $date->format('Y').'-Q'.$date->quarter,
            self::YEAR => $date->format('Y'),
        };
    }

    /**
     * Every bucket in the range, gapless — quiet days still get a point, or a
     * line chart collapses into scattered dots wherever nothing sold.
     *
     * @return array<int, array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function buckets(): array
    {
        $granularity = $this->granularity();

        $cursor = match ($granularity) {
            self::DAY => $this->from->startOfDay(),
            self::WEEK => $this->from->startOfWeek(),
            self::MONTH => $this->from->startOfMonth(),
            self::QUARTER => $this->from->startOfQuarter(),
            self::YEAR => $this->from->startOfYear(),
        };

        $buckets = [];

        while ($cursor->lte($this->to)) {
            $end = match ($granularity) {
                self::DAY => $cursor->endOfDay(),
                self::WEEK => $cursor->endOfWeek(),
                self::MONTH => $cursor->endOfMonth(),
                self::QUARTER => $cursor->endOfQuarter(),
                self::YEAR => $cursor->endOfYear(),
            };

            $buckets[] = [
                'key' => $this->bucketKey($cursor),
                'label' => match ($granularity) {
                    self::DAY => $cursor->format('M j'),
                    self::WEEK => $cursor->format('M j'),
                    self::MONTH => $cursor->format('M Y'),
                    self::QUARTER => 'Q'.$cursor->quarter.' '.$cursor->format('Y'),
                    self::YEAR => $cursor->format('Y'),
                },
                'start' => $cursor,
                'end' => $end,
            ];

            $cursor = match ($granularity) {
                self::DAY => $cursor->addDay(),
                self::WEEK => $cursor->addWeek(),
                self::MONTH => $cursor->addMonth(),
                self::QUARTER => $cursor->addQuarter(),
                self::YEAR => $cursor->addYear(),
            };
        }

        return $buckets;
    }

    /**
     * Fold per-day query rows into this period's buckets, zero-filling gaps.
     *
     * @param  iterable  $rows  rows carrying a bare date plus numeric columns
     * @param  string  $dateKey  the row property holding that date
     * @param  array<string, string>  $columns  output name => row property
     * @return array<int, array<string, mixed>>
     */
    public function foldDaily(iterable $rows, string $dateKey, array $columns): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $row = is_array($row) ? (object) $row : $row;

            if (empty($row->{$dateKey})) {
                continue;
            }

            $key = $this->bucketKey($row->{$dateKey});

            foreach ($columns as $out => $property) {
                $totals[$key][$out] = ($totals[$key][$out] ?? 0) + (float) ($row->{$property} ?? 0);
            }
        }

        return array_map(function (array $bucket) use ($columns, $totals) {
            $point = ['key' => $bucket['key'], 'label' => $bucket['label']];

            foreach (array_keys($columns) as $out) {
                $point[$out] = $totals[$bucket['key']][$out] ?? 0;
            }

            return $point;
        }, $this->buckets());
    }

    // -- Labels & serialisation ----------------------------

    public function label(): string
    {
        return $this->from->format('M j, Y').' - '.$this->to->format('M j, Y');
    }

    public function compareLabel(): ?string
    {
        return $this->comparisonPeriod()?->label();
    }

    public function compareModeLabel(): string
    {
        return self::COMPARE_MODES[$this->compareMode];
    }

    /** Shape used by the URL and by saved presets. Dates only — no figures. */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'compare_mode' => $this->compareMode,
            'compare_from' => $this->customCompareFrom?->toDateString(),
            'compare_to' => $this->customCompareTo?->toDateString(),
            'granularity' => $this->explicitGranularity,
        ];
    }

    public static function fromArray(array $state): self
    {
        return self::make(
            $state['from'] ?? now()->subDays(29)->toDateString(),
            $state['to'] ?? now()->toDateString(),
            $state['compare_mode'] ?? self::COMPARE_NONE,
            $state['compare_from'] ?? null,
            $state['compare_to'] ?? null,
            $state['granularity'] ?? null,
        );
    }
}
