@php $charts = $report['charts'][$section['key']] ?? ['groups' => []]; @endphp

<x-rpt.section :section="$section">
    @foreach($charts['groups'] as $group)
        <div class="rpt-figure-pair">
            @foreach($group['charts'] as $chart)
                <x-rpt.chart :id="$chart['id']" :spec="$chart['spec']" :caption="$chart['title']" :height="280" />
            @endforeach
        </div>

        @if($group['shared_scale'] ?? false)
            <p class="rpt-scale-note">Both charts share one scale, so the heights are directly comparable.</p>
        @endif
    @endforeach

    <x-rpt.note>
        Each point is everything earned up to that date, so the shape shows whether
        the period built up faster or slower than the one it is measured against.
    </x-rpt.note>
</x-rpt.section>
