<?php

namespace App\Filament\Pages;

use App\Models\ReportPreset;
use App\Services\Reports\PresetPayload;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportCharts;
use App\Services\Reports\ReportExport;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ReportRange;
use App\Support\Reports\ReportSection;
use App\Support\Reports\ReportSections;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The reports screen.
 *
 * Deliberately thin. It holds the admin's choices and nothing else: no queries,
 * no aggregation, no formatting. Everything on the page comes out of one
 * ReportBuilder payload, and the printed document builds the same payload from
 * the same choices — which is what keeps the screen and the paper from ever
 * disagreeing. The page it replaces computed roughly fifteen aggregates inline
 * and then restated them all a second time for print.
 */
class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static ?string $title = 'Reports';

    protected static ?string $navigationLabel = 'Reports';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $compareMode = ReportPeriod::COMPARE_PREVIOUS;

    #[Url]
    public string $compareFrom = '';

    #[Url]
    public string $compareTo = '';

    /**
     * Null means "let the span decide". An explicit value is never overridden —
     * an admin who asks for daily grouping over a long range gets it, with a
     * warning rather than a silent downgrade.
     */
    #[Url]
    public ?string $granularity = null;

    /**
     * Deliberately untyped.
     *
     * Livewire hydrates this straight from the query string before mount runs,
     * and the print link carries the section list comma-joined
     * (?sections=summary,colors). Typed `array`, that assignment is a TypeError
     * and the page 500s — so pasting a print URL back into the address bar, or
     * sharing one, broke the page it came from. Normalised in mount() instead,
     * which makes both spellings work.
     *
     * @var array<int, string>|string
     */
    #[Url]
    public $sections = [];

    #[Url]
    public int $topN = 10;

    /**
     * Side by side by default.
     *
     * Overlaying two periods on one chart draws the comparison against the
     * CURRENT period's date axis, which is wrong whenever the two windows are
     * different lengths — year over year across a leap year, or any custom
     * range. Split gives each its own axis, on a shared scale.
     */
    #[Url]
    public string $comparisonLayout = ReportCharts::LAYOUT_SPLIT;

    public bool $showBuilder = false;

    // -- Saved presets -------------------------------------

    public ?int $presetId = null;

    public string $presetName = '';

    /**
     * Whether the saved range is a rule ("last 30 days") or two fixed dates.
     * Defaults to the rule when the current range happens to be one, because
     * a preset that needs its dates re-picked every month saves nobody a click.
     */
    public bool $presetRolling = true;

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->subDays(29)->toDateString();
        }

        if ($this->to === '') {
            $this->to = now()->toDateString();
        }

        if (is_string($this->sections)) {
            $this->sections = array_values(array_filter(explode(',', $this->sections)));
        }

        // Unknown keys are dropped rather than rejected, so a link naming a
        // section that has since been renamed still opens.
        $this->sections = array_values(array_intersect(
            (array) $this->sections,
            array_keys(ReportSections::all()),
        ));

        if ($this->sections === []) {
            $this->sections = ReportSections::defaultKeys();
        }
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::make(
            $this->from,
            $this->to,
            $this->compareMode,
            $this->compareFrom ?: null,
            $this->compareTo ?: null,
            $this->granularity,
        );
    }

    /**
     * The one payload the whole page renders from. Computed, so it is built
     * once per request however many partials read it.
     */
    #[Computed]
    public function report(): array
    {
        return ReportBuilder::make(
            $this->period(),
            $this->sections,
            [
                'top_n' => $this->topN,
                'comparison_layout' => $this->comparisonLayout,
            ],
        )->build();
    }

    /** @return array<string, array<int, ReportSection>> */
    public function sectionCatalog(): array
    {
        return ReportSections::grouped();
    }

    public function toggleSection(string $key): void
    {
        $this->sections = in_array($key, $this->sections, true)
            ? array_values(array_diff($this->sections, [$key]))
            : [...$this->sections, $key];
    }

    public function selectAllSections(): void
    {
        $this->sections = array_keys(ReportSections::all());
    }

    public function resetSections(): void
    {
        $this->sections = ReportSections::defaultKeys();
    }

    // -- Saved presets -------------------------------------

    /** @return Collection<int, ReportPreset> */
    public function presets(): Collection
    {
        return auth()->user()
            ? ReportPreset::ownedBy(auth()->user())->get()
            : collect();
    }

    /** The current state, in the shape a preset stores. */
    private function currentState(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'sections' => $this->sections,
            'granularity' => $this->granularity,
            'compare_mode' => $this->compareMode,
            'compare_from' => $this->compareFrom,
            'compare_to' => $this->compareTo,
            'comparison_layout' => $this->comparisonLayout,
            'top_n' => $this->topN,
        ];
    }

    /** True when the current dates match a rolling rule, so the UI can say so. */
    public function detectedRange(): ?string
    {
        return ReportRange::detect($this->from, $this->to);
    }

    public function savePreset(): void
    {
        $name = trim($this->presetName);

        if ($name === '' || ! auth()->user()) {
            return;
        }

        // Saving under an existing name updates it, rather than leaving two
        // rows an admin cannot tell apart.
        $preset = ReportPreset::updateOrCreate(
            ['user_id' => auth()->id(), 'name' => $name],
            ['payload' => PresetPayload::capture($this->currentState(), $this->presetRolling)],
        );

        $this->presetId = $preset->id;
        $this->presetName = '';
    }

    public function applyPreset(int $id): void
    {
        $preset = ReportPreset::ownedBy(auth()->user())->find($id);

        if (! $preset) {
            return;
        }

        $state = PresetPayload::resolve($preset->payload ?? []);

        $this->from = $state['from'];
        $this->to = $state['to'];
        $this->sections = $state['sections'];
        $this->granularity = $state['granularity'];
        $this->compareMode = $state['compare_mode'];
        $this->compareFrom = $state['compare_from'];
        $this->compareTo = $state['compare_to'];
        $this->comparisonLayout = $state['comparison_layout'];
        $this->topN = $state['top_n'];
        $this->presetId = $preset->id;

        unset($this->report);
        $this->updated();
    }

    public function deletePreset(int $id): void
    {
        ReportPreset::ownedBy(auth()->user())->find($id)?->delete();

        if ($this->presetId === $id) {
            $this->presetId = null;
        }
    }

    /** A section's CSV link, or null when it has nothing tabular to give. */
    public function exportUrl(string $section): ?string
    {
        if (! ReportExport::isExportable($section)) {
            return null;
        }

        return route('filament.admin.reports.export', array_filter([
            'section' => $section,
            'from' => $this->from,
            'to' => $this->to,
            'compare_mode' => $this->compareMode,
            'compare_from' => $this->compareFrom ?: null,
            'compare_to' => $this->compareTo ?: null,
            'granularity' => $this->granularity,
            'top_n' => $this->topN,
        ]));
    }

    /** The printed document is the same choices, rendered on paper. */
    public function printUrl(): string
    {
        return route('filament.admin.reports.print', array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'compare_mode' => $this->compareMode,
            'compare_from' => $this->compareFrom ?: null,
            'compare_to' => $this->compareTo ?: null,
            'granularity' => $this->granularity,
            'top_n' => $this->topN,
            'layout' => $this->comparisonLayout,
            'sections' => implode(',', $this->sections),
        ]));
    }

    /**
     * Hand the browser the NEW chart specs on every change.
     *
     * The canvases sit behind wire:ignore so a morph cannot blank them — which
     * also means their data-chart attributes never update. Re-rendering from
     * the DOM therefore redrew the previous configuration, and changing the
     * grouping or the dates appeared to do nothing until a manual refresh. The
     * specs travel in the event instead, so what is drawn is always current.
     */
    public function updated(): void
    {
        $this->dispatch('rpt:charts', specs: ReportCharts::flatten($this->report['charts']));
    }
}
