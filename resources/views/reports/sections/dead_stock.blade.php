@php $dead = $data['dead_stock']['current']; @endphp

<x-rpt.section :section="$section" :as-of="$data['dead_stock']['as_of']">
    <x-rpt.stats :tiles="[
        ['label' => 'Variants unsold', 'value' => number_format($dead['variants'])],
        ['label' => 'Capital tied up', 'value' => '₱'.number_format($dead['capital'], 2), 'hint' => 'stock on hand x price'],
    ]" />

    <x-rpt.table
        :columns="[
            ['key' => 'product', 'label' => 'Product'],
            ['key' => 'color',   'label' => 'Colour'],
            ['key' => 'size',    'label' => 'Size'],
            ['key' => 'stock',   'label' => 'On hand', 'align' => 'right', 'format' => 'number'],
            ['key' => 'tied_up', 'label' => 'Tied up', 'align' => 'right', 'format' => 'money'],
        ]"
        :rows="$dead['top']"
        empty="Everything on the shelf sold at least once." />

    <x-rpt.note>
        Stock levels are current; the sales window checked is {{ $dead['sales_window'] }}.
        A colourant that only ever sells inside custom mixes is not counted as dead,
        because that stock really is moving.
    </x-rpt.note>
</x-rpt.section>
