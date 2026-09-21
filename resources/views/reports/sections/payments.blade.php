@php
    $payments = $data['payments']['current'];
    $d        = $data['payments']['deltas'];
@endphp

<x-rpt.section :section="$section">
    <x-rpt.stats :tiles="[
        ['label' => 'Settled',     'value' => '₱'.number_format($payments['paid_value'], 2),        'delta' => $d['paid_value'] ?? null],
        ['label' => 'Outstanding', 'value' => '₱'.number_format($payments['outstanding_value'], 2), 'delta' => $d['outstanding_value'] ?? null, 'invert' => true, 'hint' => 'awaiting payment'],
    ]" />

    <div class="rpt-split">
        <div>
            <h3 class="rpt-sub">By method</h3>
            <x-rpt.bars :rows="$payments['by_method']" label-key="label" value-key="total" />
        </div>
        <div>
            <h3 class="rpt-sub">By payment status</h3>
            <x-rpt.table
                :columns="[
                    ['key' => 'label', 'label' => 'Status'],
                    ['key' => 'count', 'label' => 'Orders', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'total', 'label' => 'Value',  'align' => 'right', 'format' => 'money'],
                ]"
                :rows="$payments['by_status']" />
        </div>
    </div>
</x-rpt.section>
