@props([
    'rows' => [],
    'labelKey' => 'label',
    'valueKey' => 'revenue',
    'metaKey' => null,
    'swatchKey' => null,
    'money' => true,
    'empty' => 'Nothing in this period.',
])

@php
    $max = collect($rows)->max(fn ($row) => (float) ($row[$valueKey] ?? 0)) ?: 1;
@endphp

@if(empty($rows))
    <p class="rpt-empty">{{ $empty }}</p>
@else
    <ol class="rpt-bars">
        @foreach($rows as $row)
            @php $value = (float) ($row[$valueKey] ?? 0); @endphp
            <li class="rpt-bar-row">
                <div class="rpt-bar-head">
                    <span class="rpt-bar-label">
                        @if($swatchKey)
                            <x-rpt.swatch :hex="$row[$swatchKey] ?? null" />
                        @endif
                        {{ $row[$labelKey] ?? '-' }}
                        @if($metaKey && ! empty($row[$metaKey]))
                            <span class="rpt-bar-meta">{{ $row[$metaKey] }}</span>
                        @endif
                    </span>
                    {{-- Direct label on every bar: these hues sit below 3:1 on a
                         light surface, so the value must be legible without them. --}}
                    <span class="rpt-bar-value">{{ $money ? '₱'.number_format($value, 2) : number_format($value) }}</span>
                </div>
                <div class="rpt-bar-track">
                    <div class="rpt-bar-fill" style="width:{{ max(1.5, round($value / $max * 100, 2)) }}%"></div>
                </div>
            </li>
        @endforeach
    </ol>
@endif
