@php
    $c = $data['cancellations']['current'];
    $d = $data['cancellations']['deltas'];
@endphp

<x-rpt.section :section="$section">
    <x-rpt.stats :tiles="[
        ['label' => 'Cancelled',           'value' => number_format($c['cancelled_orders']),        'delta' => $d['cancelled_orders'] ?? null,  'invert' => true],
        ['label' => 'Cancellation rate',   'value' => $c['cancellation_rate'].'%',                  'delta' => $d['cancellation_rate'] ?? null, 'invert' => true, 'hint' => 'of all orders placed'],
        ['label' => 'Value lost',          'value' => '₱'.number_format($c['value_lost'], 2), 'delta' => $d['value_lost'] ?? null,        'invert' => true],
        ['label' => 'Customer / store',    'value' => $c['by_customer'].' / '.$c['by_store'],       'hint' => $c['by_unrecorded'] > 0 ? $c['by_unrecorded'].' unrecorded' : null],
    ]" />

    <h3 class="rpt-sub">Reasons given</h3>
    <x-rpt.table
        :columns="[
            ['key' => 'reason', 'label' => 'Reason'],
            ['key' => 'count',  'label' => 'Orders', 'align' => 'right', 'format' => 'number'],
            ['key' => 'value',  'label' => 'Value',  'align' => 'right', 'format' => 'money'],
        ]"
        :rows="$c['by_reason']"
        empty="No orders were cancelled in this period." />

    <x-rpt.note>
        Counted against the period the order was PLACED in, so this rate and the
        revenue above describe the same set of orders.
    </x-rpt.note>
</x-rpt.section>
