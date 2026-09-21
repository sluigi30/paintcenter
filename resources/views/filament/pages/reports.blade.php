<x-filament-panels::page>
@php $report = $this->report; @endphp

<div class="rpt-root">
    {{-- Token values for the screen. The blocks themselves are styled once, in
         the shared stylesheet; only these values differ from the paper. --}}
    <style>
        .rpt-root {
            --rpt-surface:  #fcfcfb;
            --rpt-plane:    #f4f4f1;
            --rpt-ink:      #0b0b0b;
            --rpt-ink-2:    #52514e;
            --rpt-muted:    #898781;
            --rpt-grid:     #e1e0d9;
            --rpt-axis:     #c3c2b7;
            --rpt-border:   rgba(11, 11, 11, 0.10);
            --rpt-series-1: #2a78d6;
            --rpt-series-2: #eb6834;
            --rpt-good:     #006300;
            --rpt-bad:      #d03b3b;
            --rpt-brand:    #b91c1c;
        }

        /* Both scopes: the media query covers the OS setting, the class covers
           Filament's own theme toggle, which has to win either way. */
        @media (prefers-color-scheme: dark) {
            :root:not(.light) .rpt-root {
                --rpt-surface:  #1a1a19;
                --rpt-plane:    #232322;
                --rpt-ink:      #ffffff;
                --rpt-ink-2:    #c3c2b7;
                --rpt-muted:    #898781;
                --rpt-grid:     #2c2c2a;
                --rpt-axis:     #383835;
                --rpt-border:   rgba(255, 255, 255, 0.10);
                --rpt-series-1: #3987e5;
                --rpt-series-2: #d95926;
                --rpt-good:     #0ca30c;
                --rpt-bad:      #e66767;
                --rpt-brand:    #ef4444;
            }
        }

        html.dark .rpt-root {
            --rpt-surface:  #1a1a19;
            --rpt-plane:    #232322;
            --rpt-ink:      #ffffff;
            --rpt-ink-2:    #c3c2b7;
            --rpt-muted:    #898781;
            --rpt-grid:     #2c2c2a;
            --rpt-axis:     #383835;
            --rpt-border:   rgba(255, 255, 255, 0.10);
            --rpt-series-1: #3987e5;
            --rpt-series-2: #d95926;
            --rpt-good:     #0ca30c;
            --rpt-bad:      #e66767;
            --rpt-brand:    #ef4444;
        }

        .rpt-toolbar {
            background: var(--rpt-surface);
            border: 1px solid var(--rpt-border);
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 16px;
        }

        .rpt-toolbar-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .rpt-eyebrow {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--rpt-brand);
            margin: 0 0 3px;
        }

        .rpt-period {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--rpt-ink);
            margin: 0;
        }

        .rpt-period-sub {
            font-size: 12px;
            color: var(--rpt-ink-2);
            margin: 3px 0 0;
        }

        .rpt-controls {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .rpt-field { display: flex; flex-direction: column; gap: 5px; }

        .rpt-field-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--rpt-muted);
        }

        .rpt-input,
        .rpt-select {
            background: var(--rpt-plane);
            border: 1px solid var(--rpt-border);
            border-radius: 7px;
            padding: 6px 9px;
            font-size: 12.5px;
            color: var(--rpt-ink);
            font-family: inherit;
        }

        .rpt-range { display: flex; align-items: center; gap: 6px; }
        .rpt-range-sep { color: var(--rpt-muted); }

        .rpt-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--rpt-plane);
            border: 1px solid var(--rpt-border);
            border-radius: 7px;
            padding: 7px 13px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--rpt-ink);
            cursor: pointer;
            font-family: inherit;
        }

        .rpt-btn:hover { border-color: var(--rpt-axis); }

        .rpt-btn-primary {
            background: var(--rpt-brand);
            border-color: var(--rpt-brand);
            color: #ffffff;
        }

        .rpt-warn {
            font-size: 11.5px;
            color: var(--rpt-ink-2);
            background: var(--rpt-plane);
            border: 1px solid var(--rpt-border);
            border-left: 3px solid var(--rpt-series-2);
            border-radius: 6px;
            padding: 7px 11px;
            margin-top: 12px;
        }

        .rpt-builder {
            border-top: 1px solid var(--rpt-border);
            margin-top: 14px;
            padding-top: 14px;
        }

        .rpt-builder-groups {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
        }

        .rpt-builder-group-name {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--rpt-muted);
            margin: 0 0 6px;
        }

        .rpt-check {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            font-size: 12.5px;
            color: var(--rpt-ink);
            padding: 3px 0;
            cursor: pointer;
        }

        .rpt-check input { margin-top: 2px; flex: none; }
        .rpt-check-print { font-size: 10px; color: var(--rpt-muted); }

        .rpt-builder-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .rpt-count { font-size: 11.5px; color: var(--rpt-ink-2); }

        .rpt-presets {
            border-top: 1px solid var(--rpt-border);
            margin-top: 14px;
            padding-top: 12px;
        }

        .rpt-preset {
            display: flex;
            align-items: center;
            gap: 9px;
            flex-wrap: wrap;
            padding: 4px 0;
        }

        .rpt-preset-open {
            font: inherit;
            font-size: 12.5px;
            font-weight: 600;
            background: var(--rpt-plane);
            border: 1px solid var(--rpt-border);
            border-radius: 7px;
            padding: 5px 11px;
            color: var(--rpt-ink);
            cursor: pointer;
        }

        .rpt-preset-open.active {
            border-color: var(--rpt-brand);
            color: var(--rpt-brand);
        }

        .rpt-preset-meta { font-size: 11px; color: var(--rpt-muted); }

        .rpt-preset-del {
            font: inherit;
            font-size: 11px;
            background: none;
            border: 0;
            color: var(--rpt-muted);
            text-decoration: underline;
            cursor: pointer;
            padding: 0;
        }

        .rpt-preset-del:hover { color: var(--rpt-bad); }

        .rpt-preset-save {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
    </style>

    @include('reports.partials.styles')

    {{-- ── Toolbar ─────────────────────────────────────────── --}}
    <div class="rpt-toolbar">
        <div class="rpt-toolbar-head">
            <div>
                <p class="rpt-eyebrow">Reporting period</p>
                <p class="rpt-period">{{ $report['period']['label'] }}</p>
                <p class="rpt-period-sub">
                    {{ $report['period']['day_count'] }} {{ \Illuminate\Support\Str::plural('day', $report['period']['day_count']) }}
                    &middot; grouped by {{ $report['period']['granularity_label'] }}
                    @if($report['period']['has_comparison'])
                        &middot; vs {{ $report['period']['compare_label'] }}
                    @endif
                </p>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="rpt-btn" wire:click="$toggle('showBuilder')">
                    {{ $showBuilder ? 'Hide sections' : 'Build report' }}
                    <span class="rpt-count">({{ count($sections) }})</span>
                </button>
                <a class="rpt-btn rpt-btn-primary" href="{{ $this->printUrl() }}" target="_blank" rel="noopener">
                    Print / save PDF
                </a>
            </div>
        </div>

        <div class="rpt-controls">
            <div class="rpt-field">
                <span class="rpt-field-label">Date range</span>
                <div class="rpt-range">
                    <input type="date" class="rpt-input" wire:model.live="from" max="{{ $to }}">
                    <span class="rpt-range-sep">&ndash;</span>
                    <input type="date" class="rpt-input" wire:model.live="to" min="{{ $from }}" max="{{ now()->toDateString() }}">
                </div>
            </div>

            <div class="rpt-field">
                <span class="rpt-field-label">Group by</span>
                <select class="rpt-select" wire:model.live="granularity">
                    <option value="">Auto ({{ \App\Services\Reports\ReportPeriod::GRANULARITIES[$report['period']['granularity_resolved']] }})</option>
                    @foreach(\App\Services\Reports\ReportPeriod::GRANULARITIES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="rpt-field">
                <span class="rpt-field-label">Compare with</span>
                <select class="rpt-select" wire:model.live="compareMode">
                    @foreach(\App\Services\Reports\ReportPeriod::COMPARE_MODES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if($report['period']['has_comparison'])
                <div class="rpt-field">
                    <span class="rpt-field-label">Comparison view</span>
                    <select class="rpt-select" wire:model.live="comparisonLayout">
                        @foreach(\App\Services\Reports\ReportCharts::LAYOUTS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if($compareMode === \App\Services\Reports\ReportPeriod::COMPARE_CUSTOM)
                <div class="rpt-field">
                    <span class="rpt-field-label">Comparison range</span>
                    <div class="rpt-range">
                        <input type="date" class="rpt-input" wire:model.live="compareFrom" max="{{ $compareTo }}">
                        <span class="rpt-range-sep">&ndash;</span>
                        <input type="date" class="rpt-input" wire:model.live="compareTo" min="{{ $compareFrom }}">
                    </div>
                </div>
            @endif

            <div class="rpt-field">
                <span class="rpt-field-label">Rows per table</span>
                <select class="rpt-select" wire:model.live="topN">
                    @foreach([5, 10, 15, 25] as $option)
                        <option value="{{ $option }}">Top {{ $option }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- The mirror of the too-many-buckets warning. Without it, choosing a
             grouping coarser than the range looks like the page ignored you. --}}
        @if($report['period']['single_bucket'] ?? false)
            <p class="rpt-warn">
                This range falls inside a single {{ $report['period']['granularity_resolved'] }},
                so the chart is one bar holding the period's total and shows no trend.
                @if($report['period']['finer_granularity'])
                    Group by {{ \App\Services\Reports\ReportPeriod::GRANULARITIES[$report['period']['finer_granularity']] }}
                    for detail, or widen the date range.
                @else
                    Widen the date range to see a trend.
                @endif
            </p>
        @endif

        {{-- Warned, never overridden: an explicit grouping is the admin's call. --}}
        @if($report['period']['bucket_warning'])
            <p class="rpt-warn">
                This range is grouped into {{ $report['period']['bucket_count'] }} points, which is more than a chart reads well.
                Grouping by week or month will be clearer &mdash; the choice stays yours.
            </p>
        @endif

        @if($showBuilder)
            <div class="rpt-builder">
                <div class="rpt-builder-groups">
                    @foreach($this->sectionCatalog() as $group => $groupSections)
                        <div>
                            <p class="rpt-builder-group-name">{{ $group }}</p>
                            @foreach($groupSections as $catalogSection)
                                <label class="rpt-check">
                                    <input type="checkbox"
                                           wire:click="toggleSection('{{ $catalogSection->key }}')"
                                           @checked(in_array($catalogSection->key, $sections, true))>
                                    <span>
                                        {{ $catalogSection->label }}
                                        @if($catalogSection->printOnly)
                                            <span class="rpt-check-print">PAPER ONLY</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                <div class="rpt-builder-actions">
                    <button type="button" class="rpt-btn" wire:click="selectAllSections">Select all</button>
                    <button type="button" class="rpt-btn" wire:click="resetSections">Reset to default</button>
                    <span class="rpt-count">{{ count($sections) }} of {{ count(\App\Support\Reports\ReportSections::all()) }} sections included</span>
                </div>

                {{-- ── Saved presets ───────────────────────────
                     A preset stores the CONFIGURATION above, never the figures —
                     opening one re-runs every query against today's data. --}}
                <div class="rpt-presets">
                    <p class="rpt-builder-group-name">Saved reports</p>

                    @forelse($this->presets() as $preset)
                        <div class="rpt-preset">
                            <button type="button"
                                    class="rpt-preset-open {{ $presetId === $preset->id ? 'active' : '' }}"
                                    wire:click="applyPreset({{ $preset->id }})">
                                {{ $preset->name }}
                            </button>
                            <span class="rpt-preset-meta">
                                {{ $preset->range_label }} &middot; {{ $preset->section_count }} sections
                            </span>
                            <button type="button" class="rpt-preset-del"
                                    wire:click="deletePreset({{ $preset->id }})"
                                    wire:confirm="Delete the saved report &ldquo;{{ $preset->name }}&rdquo;?">
                                Delete
                            </button>
                        </div>
                    @empty
                        <p class="rpt-count" style="margin:0 0 9px">
                            Nothing saved yet. Set the report up the way you want it, then name and save it below.
                        </p>
                    @endforelse

                    <div class="rpt-preset-save">
                        <input type="text" class="rpt-input" placeholder="Name this report…"
                               wire:model="presetName" wire:keydown.enter="savePreset" maxlength="60">

                        <label class="rpt-check" style="padding:0">
                            <input type="checkbox" wire:model.live="presetRolling"
                                   @disabled(! $this->detectedRange())>
                            <span>
                                @if($this->detectedRange())
                                    Remember as &ldquo;{{ \App\Services\Reports\ReportRange::label($this->detectedRange()) }}&rdquo;
                                @else
                                    {{-- Only offered when the dates actually match a rule; a
                                         one-off range can only be saved as fixed dates. --}}
                                    Fixed dates (this range matches no rolling rule)
                                @endif
                            </span>
                        </label>

                        <button type="button" class="rpt-btn rpt-btn-primary" wire:click="savePreset">Save</button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- ── Sections ────────────────────────────────────────── --}}
    @forelse($report['sections'] as $section)
        @continue($section['print_only'])

        @include('reports.sections.' . $section['key'], [
            'section' => $section + ['export_url' => $this->exportUrl($section['key'])],
            'data'    => $section['data'],
            'report'  => $report,
        ])
    @empty
        <p class="rpt-empty">No sections selected. Use &ldquo;Build report&rdquo; to choose what to show.</p>
    @endforelse
</div>

@include('reports.partials.charts', ['print' => false])
</x-filament-panels::page>
