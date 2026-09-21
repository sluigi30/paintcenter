@php $orders = $data['orders']['current']; @endphp

<x-rpt.section :section="$section">
    <div class="rpt-split">
        <div>
            <h3 class="rpt-sub">By status</h3>
            <x-rpt.bars :rows="$orders['by_status']" label-key="label" value-key="count" :money="false" />
            <x-rpt.note>{{ $orders['status_note'] }}</x-rpt.note>
        </div>
        <div>
            <h3 class="rpt-sub">Delivery vs pickup</h3>
            <x-rpt.table
                :columns="[
                    ['key' => 'label',   'label' => 'Type'],
                    ['key' => 'count',   'label' => 'Orders',  'align' => 'right', 'format' => 'number'],
                    ['key' => 'revenue', 'label' => 'Revenue', 'align' => 'right', 'format' => 'money'],
                ]"
                :rows="$orders['by_type']" />
        </div>
    </div>
</x-rpt.section>
