@php
    $sales  = $data['sales']['current'];
    $orders = $data['orders']['current'];
    $d      = $data['sales']['deltas'];
@endphp

<x-rpt.section :section="$section">
    <x-rpt.stats :tiles="[
        ['label' => 'Revenue',       'value' => '₱'.number_format($sales['revenue'], 2),         'delta' => $d['revenue'] ?? null],
        ['label' => 'Orders',        'value' => number_format($sales['orders']),                       'delta' => $d['orders'] ?? null],
        ['label' => 'Average order', 'value' => '₱'.number_format($sales['avg_order_value'], 2), 'delta' => $d['avg_order_value'] ?? null],
        ['label' => 'Orders placed', 'value' => number_format($orders['total_orders']),                'hint' => 'including cancelled'],
    ]" />

    @if($sales['best_day'])
        <x-rpt.note>
            Busiest {{ $report['period']['granularity_resolved'] }}:
            <strong>{{ $sales['best_day']['label'] }}</strong>
            at ₱{{ number_format($sales['best_day']['revenue'], 2) }}.
        </x-rpt.note>
    @endif
</x-rpt.section>
