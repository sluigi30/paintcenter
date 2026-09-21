@props(['delta' => null, 'invert' => false])

{{-- Direction is carried by an arrow AND the words, never by colour alone.
     A null percent means the previous figure was zero: growth from nothing has
     no percentage, so it reads "new" rather than a misleading +100%. --}}
@if($delta)
    @php
        $direction = $delta['direction'];
        $improving = $invert ? $direction < 0 : $direction > 0;
        $tone = $direction === 0 ? 'flat' : ($improving ? 'up' : 'down');
        $percent = $delta['percent'];
    @endphp
    <span class="rpt-delta rpt-delta-{{ $tone }}">
        <span class="rpt-delta-arrow" aria-hidden="true">{{ $direction > 0 ? '▲' : ($direction < 0 ? '▼' : '—') }}</span>
        @if($percent === null)
            new
        @else
            {{ $percent > 0 ? '+' : '' }}{{ $percent }}%
        @endif
    </span>
@endif
