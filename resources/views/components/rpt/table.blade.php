@props(['columns' => [], 'rows' => [], 'empty' => 'Nothing in this period.'])

@if(empty($rows))
    <p class="rpt-empty">{{ $empty }}</p>
@else
    <table class="rpt-table">
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th class="rpt-align-{{ $column['align'] ?? 'left' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($columns as $column)
                        @php
                            $raw = $row[$column['key']] ?? null;
                            $format = $column['format'] ?? 'text';
                        @endphp
                        <td class="rpt-align-{{ $column['align'] ?? 'left' }}">
                            @if($format === 'money')
                                ₱{{ number_format((float) $raw, 2) }}
                            @elseif($format === 'number')
                                {{ number_format((float) $raw) }}
                            @elseif($format === 'percent')
                                {{ $raw }}%
                            @elseif($format === 'swatch')
                                <x-rpt.swatch :hex="$raw" />
                            @else
                                {{ $raw ?? '-' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
