@php $charts = $report['charts'][$section['key']] ?? ['groups' => []]; @endphp

<x-rpt.section :section="$section">
    @foreach($charts['groups'] as $group)
        <div class="rpt-chart-group">
            <h3 class="rpt-sub">{{ $group['caption'] }}</h3>

            {{-- Side by side, each period keeps its OWN date axis; the pair is
                 locked to one y scale by the spec so the heights stay honest. --}}
            <div class="rpt-figure-pair">
                @foreach($group['charts'] as $chart)
                    <x-rpt.chart :id="$chart['id']" :spec="$chart['spec']" :caption="$chart['title']" />
                @endforeach
            </div>

            @if($group['shared_scale'] ?? false)
                <p class="rpt-scale-note">Both charts share one scale, so the heights are directly comparable.</p>
            @endif
        </div>
    @endforeach

    @if($report['period']['single_bucket'] ?? false)
        <x-rpt.note>
            The whole range sits inside one {{ $report['period']['granularity_resolved'] }},
            so this is the period's total rather than a trend.
        </x-rpt.note>
    @endif

    {{-- At quarter and year a label like "2026" can name a bucket the range
         only partly covers, which reads as a full year of trading. --}}
    @if($report['period']['partial_buckets'] ?? false)
        <x-rpt.note>
            The first and last {{ $report['period']['granularity_resolved'] }} are partial &mdash;
            they hold only the days inside {{ $report['period']['label'] }}, not the whole
            {{ $report['period']['granularity_resolved'] }} their label names.
        </x-rpt.note>
    @endif

    @if($report['period']['bucket_warning'])
        <x-rpt.note>
            This range is grouped into {{ $report['period']['bucket_count'] }} points.
            A coarser grouping will read more clearly.
        </x-rpt.note>
    @endif
</x-rpt.section>
