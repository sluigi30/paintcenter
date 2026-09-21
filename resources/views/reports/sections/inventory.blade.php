@php $inventory = $data['inventory']['current']; @endphp

<x-rpt.section :section="$section" :as-of="$data['inventory']['as_of']">
    <x-rpt.stats :tiles="[
        ['label' => 'Stock value',  'value' => '₱'.number_format($inventory['stock_value'], 2)],
        ['label' => 'Cans on hand', 'value' => number_format($inventory['units']),     'hint' => $inventory['variants'].' variants'],
        ['label' => 'Low stock',    'value' => number_format($inventory['low_stock']), 'hint' => 'at or below threshold'],
        ['label' => 'Out of stock', 'value' => number_format($inventory['out_of_stock'])],
    ]" />

    <x-rpt.note>
        Stock is held per variant: one colour, size and base is one can on the
        shelf, counted and priced on its own.
    </x-rpt.note>
</x-rpt.section>
