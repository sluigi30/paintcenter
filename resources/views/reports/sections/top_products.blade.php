@php $products = $data['products']['current']; @endphp

<x-rpt.section :section="$section">
    <x-rpt.bars :rows="$products['top']" label-key="name" meta-key="brand" value-key="revenue" />

    <x-rpt.note>
        {{ number_format($products['distinct_products']) }} products sold,
        {{ number_format($products['units']) }} cans in total.
        A can tinted at the counter counts its base and each colourant separately
        here, because all of them really left the shelf.
    </x-rpt.note>
</x-rpt.section>
