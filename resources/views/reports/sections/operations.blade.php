@php $ops = $data['operations']['current']; @endphp

<x-rpt.section :section="$section" :as-of="$data['operations']['as_of']">
    <x-rpt.stats :tiles="[
        ['label' => 'Open orders', 'value' => number_format($ops['open_orders'])],
        ['label' => 'Value held',  'value' => '₱'.number_format($ops['open_value'], 2)],
    ]" />

    <x-rpt.table
        :columns="[
            ['key' => 'label', 'label' => 'Status'],
            ['key' => 'count', 'label' => 'Orders', 'align' => 'right', 'format' => 'number'],
            ['key' => 'value', 'label' => 'Value',  'align' => 'right', 'format' => 'money'],
        ]"
        :rows="$ops['by_status']"
        empty="Nothing is outstanding." />

    <x-rpt.note>
        These are orders open right now, whenever they were placed. They are not
        limited to the reporting period on the cover.
    </x-rpt.note>
</x-rpt.section>
