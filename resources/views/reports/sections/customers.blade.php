@php
    $customers = $data['customers']['current'];
    $d         = $data['customers']['deltas'];
@endphp

<x-rpt.section :section="$section">
    <x-rpt.stats :tiles="[
        ['label' => 'Buyers',            'value' => number_format($customers['buyers'])],
        ['label' => 'First-time buyers', 'value' => number_format($customers['first_time_buyers']), 'delta' => $d['first_time_buyers'] ?? null, 'hint' => '₱'.number_format($customers['new_revenue'], 2)],
        ['label' => 'Returning buyers',  'value' => number_format($customers['returning_buyers']),  'delta' => $d['returning_buyers'] ?? null,  'hint' => '₱'.number_format($customers['returning_revenue'], 2)],
        ['label' => 'New sign-ups',      'value' => number_format($customers['new_registrations']), 'delta' => $d['new_registrations'] ?? null, 'hint' => 'accounts created'],
    ]" />

    <h3 class="rpt-sub">Top customers</h3>
    <x-rpt.table
        :columns="[
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'orders',   'label' => 'Orders',  'align' => 'right', 'format' => 'number'],
            ['key' => 'revenue',  'label' => 'Revenue', 'align' => 'right', 'format' => 'money'],
        ]"
        :rows="$customers['top']" />

    <x-rpt.note>
        A buyer counts as first-time when their earliest order of all time falls
        inside this period, not merely their earliest order within it.
    </x-rpt.note>
</x-rpt.section>
