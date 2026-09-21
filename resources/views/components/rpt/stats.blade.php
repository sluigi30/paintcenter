@props(['tiles' => []])

<div class="rpt-stats">
    @foreach($tiles as $tile)
        <div class="rpt-stat">
            <p class="rpt-stat-label">{{ $tile['label'] }}</p>
            <p class="rpt-stat-value">{{ $tile['value'] }}</p>
            <div class="rpt-stat-foot">
                <x-rpt.delta :delta="$tile['delta'] ?? null" :invert="$tile['invert'] ?? false" />
                @if(! empty($tile['hint']))
                    <span class="rpt-stat-hint">{{ $tile['hint'] }}</span>
                @endif
            </div>
        </div>
    @endforeach
</div>
