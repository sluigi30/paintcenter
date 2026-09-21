<?php

namespace App\Services\Reports;

use App\Support\Reports\ReportSections;

/**
 * The stored shape of a saved report, and the rules for reading it back.
 *
 * Two things this is careful about:
 *
 *  - It holds CONFIGURATION, never figures. Opening a preset re-runs every
 *    query, so a saved report always describes the database as it is now.
 *
 *  - It is VERSIONED, and reading is forgiving. A section that has since been
 *    renamed is dropped rather than throwing, so an old preset still opens —
 *    with the sections that still exist — instead of becoming a dead row the
 *    admin has to work out how to delete.
 */
final class PresetPayload
{
    public const VERSION = 1;

    /**
     * Capture the page's current configuration.
     *
     * @param  array<string, mixed>  $state
     */
    public static function capture(array $state, bool $rollingRange = true): array
    {
        $from = (string) ($state['from'] ?? '');
        $to = (string) ($state['to'] ?? '');

        $rolling = $rollingRange ? ReportRange::detect($from, $to) : null;

        return [
            'v' => self::VERSION,

            'range' => $rolling
                ? ['mode' => 'rolling', 'key' => $rolling]
                : ['mode' => ReportRange::FIXED, 'from' => $from, 'to' => $to],

            'sections' => array_values(array_intersect(
                (array) ($state['sections'] ?? []),
                array_keys(ReportSections::all()),
            )),

            // Every key defaulted: a caller may legitimately hand over a
            // partial state, and a missing key must mean "use the default",
            // never a warning.
            'granularity' => ($state['granularity'] ?? null) ?: null,
            'compare_mode' => (string) ($state['compare_mode'] ?? ReportPeriod::COMPARE_PREVIOUS),
            'compare_from' => ($state['compare_from'] ?? null) ?: null,
            'compare_to' => ($state['compare_to'] ?? null) ?: null,
            'comparison_layout' => (string) ($state['comparison_layout'] ?? ReportCharts::LAYOUT_SPLIT),
            'top_n' => (int) ($state['top_n'] ?? 10),
        ];
    }

    /**
     * Turn a stored payload back into page state, resolving a rolling range
     * against today.
     *
     * @return array<string, mixed>
     */
    public static function resolve(array $payload): array
    {
        $range = $payload['range'] ?? [];

        if (($range['mode'] ?? null) === 'rolling') {
            [$from, $to] = ReportRange::resolve((string) ($range['key'] ?? 'last_30_days'));
        } else {
            $from = $range['from'] ?? now()->subDays(29)->toDateString();
            $to = $range['to'] ?? now()->toDateString();
        }

        // Sections are re-filtered on the way OUT as well as in: the catalogue
        // may have changed since this was saved.
        $sections = array_values(array_intersect(
            (array) ($payload['sections'] ?? []),
            array_keys(ReportSections::all()),
        ));

        return [
            'from' => $from,
            'to' => $to,
            'sections' => $sections ?: ReportSections::defaultKeys(),
            'granularity' => array_key_exists((string) ($payload['granularity'] ?? ''), ReportPeriod::GRANULARITIES)
                ? $payload['granularity']
                : null,
            'compare_mode' => array_key_exists((string) ($payload['compare_mode'] ?? ''), ReportPeriod::COMPARE_MODES)
                ? $payload['compare_mode']
                : ReportPeriod::COMPARE_PREVIOUS,
            'compare_from' => $payload['compare_from'] ?? '',
            'compare_to' => $payload['compare_to'] ?? '',
            'comparison_layout' => array_key_exists((string) ($payload['comparison_layout'] ?? ''), ReportCharts::LAYOUTS)
                ? $payload['comparison_layout']
                : ReportCharts::LAYOUT_SPLIT,
            'top_n' => max(1, (int) ($payload['top_n'] ?? 10)),
        ];
    }

    /** How the saved range reads in the presets list. */
    public static function rangeLabel(array $payload): string
    {
        $range = $payload['range'] ?? [];

        if (($range['mode'] ?? null) === 'rolling') {
            return ReportRange::label((string) ($range['key'] ?? ''));
        }

        return trim(($range['from'] ?? '?').' to '.($range['to'] ?? '?'));
    }
}
