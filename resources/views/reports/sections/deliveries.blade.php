@php $d = $data['deliveries']['current']; @endphp

<x-rpt.section :section="$section">
    <x-rpt.stats :tiles="[
        ['label' => 'Delivered',        'value' => number_format($d['completed'])],
        ['label' => 'First-time rate',  'value' => $d['first_time_rate'] === null ? '—' : $d['first_time_rate'].'%'],
        ['label' => 'Needed a retry',   'value' => number_format($d['needed_retry'])],
        ['label' => 'Avg. on the road', 'value' => $d['avg_minutes'] === null ? '—' : $d['avg_minutes'].' min'],
    ]" />

    <x-rpt.table
        :columns="[
            ['key' => 'driver',          'label' => 'Driver'],
            ['key' => 'delivered',       'label' => 'Delivered',       'align' => 'right', 'format' => 'number'],
            ['key' => 'with_proof',      'label' => 'With photo',      'align' => 'right', 'format' => 'number'],
            ['key' => 'failed_attempts', 'label' => 'Failed attempts', 'align' => 'right', 'format' => 'number'],
            ['key' => 'cash_collected',  'label' => 'COD collected',   'align' => 'right', 'format' => 'money'],
        ]"
        :rows="$d['by_driver']"
        empty="No deliveries were completed in this period." />

    @if ($d['stuck_at_limit'] > 0)
        <x-rpt.note>
            <strong>{{ number_format($d['stuck_at_limit']) }}</strong>
            {{ $d['stuck_at_limit'] === 1 ? 'delivery is' : 'deliveries are' }}
            still out after {{ $d['max_attempts'] }} failed attempts and cannot be
            attempted again. These need cancelling or reassigning. This is a count
            of right now, not of the reporting period.
        </x-rpt.note>
    @endif

    <x-rpt.note>
        Counted by when each delivery was HANDED OVER, not when the order was
        placed — so this measures work done in the period and will not match the
        period's order count. A delivery completed with no driver named was
        closed by the store through the admin override.
    </x-rpt.note>
</x-rpt.section>
