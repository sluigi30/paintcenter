@props(['section', 'asOf' => null])

<section class="rpt-section rpt-break-{{ $section['page_break'] ?? 'auto' }}" data-section="{{ $section['key'] }}">
    <header class="rpt-section-head">
        <div class="rpt-section-titlebar">
            <h2 class="rpt-section-title">{{ $section['label'] }}</h2>

            {{-- Only the screen shell passes an export_url; the printed
                 document leaves it out, so paper never carries a dead link. --}}
            @if(! empty($section['export_url']))
                <a class="rpt-section-csv" href="{{ $section['export_url'] }}">CSV</a>
            @endif
        </div>
        @if(! empty($section['description']))
            <p class="rpt-section-desc">{{ $section['description'] }}</p>
        @endif

        {{-- A point-in-time block inside a report headed with a past date range
             has to say so, or the paper claims last month had today's numbers. --}}
        @if($asOf)
            <p class="rpt-asof">As of {{ \Illuminate\Support\Carbon::parse($asOf)->format('M j, Y g:i A') }}</p>
        @endif
    </header>

    {{ $slot }}
</section>
