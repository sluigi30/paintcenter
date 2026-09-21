<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * Rolling date ranges, for presets that have to still make sense next month.
 *
 * A saved report keeps its range as a RULE ("last 30 days") rather than as two
 * dates, and the rule is resolved when the preset is opened. Storing the dates
 * would mean re-picking them on every use, which is the work the preset exists
 * to remove.
 *
 * Deliberately NOT wired into the toolbar as a quick-range dropdown. That was
 * tried and removed in July 2026 (CONTEXT.md section 9) in favour of a single
 * date picker; this is only the vocabulary presets are saved in.
 */
final class ReportRange
{
    public const FIXED = 'fixed';

    /** @var array<string, string> */
    public const ROLLING = [
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'last_90_days' => 'Last 90 days',
        'this_month' => 'This month so far',
        'last_month' => 'Last month',
        'year_to_date' => 'Year to date',
    ];

    public static function label(string $key): string
    {
        return self::ROLLING[$key] ?? 'Fixed dates';
    }

    /**
     * Resolve a rolling key against today.
     *
     * @return array{0: string, 1: string} from, to as Y-m-d
     */
    public static function resolve(string $key): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        [$from, $to] = match ($key) {
            'last_7_days' => [$today->subDays(6), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'last_90_days' => [$today->subDays(89), $today],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth(),
            ],
            'year_to_date' => [$today->startOfYear(), $today],
            default => [$today->subDays(29), $today],
        };

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * Which rolling rule, if any, describes this pair of dates.
     *
     * Used to pick a sensible default when saving: someone looking at the last
     * 30 days almost certainly wants the preset to mean "the last 30 days",
     * not "1 August to 30 September" forever.
     */
    public static function detect(string $from, string $to): ?string
    {
        foreach (array_keys(self::ROLLING) as $key) {
            [$candidateFrom, $candidateTo] = self::resolve($key);

            if ($candidateFrom === $from && $candidateTo === $to) {
                return $key;
            }
        }

        return null;
    }
}
