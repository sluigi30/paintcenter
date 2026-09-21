@php $categories = $data['categories']['current']; @endphp

<x-rpt.section :section="$section">
    <x-rpt.bars :rows="$categories['top']" label-key="category" value-key="revenue" />
    <x-rpt.note>{{ $categories['caveat'] }}</x-rpt.note>
</x-rpt.section>
