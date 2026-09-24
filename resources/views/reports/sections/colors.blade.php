@php
    $colors = $data['colors']['current'];
    $d      = $data['colors']['deltas'];
@endphp

<x-rpt.section :section="$section">
    <x-rpt.stats :tiles="[
        ['label' => 'Factory shades',   'value' => '₱'.number_format($colors['factory_revenue'], 2), 'delta' => $d['factory_revenue'] ?? null, 'hint' => $colors['distinct_shades'].' shades sold'],
        ['label' => 'Custom mixes',     'value' => '₱'.number_format($colors['custom_revenue'], 2),  'delta' => $d['custom_revenue'] ?? null,  'hint' => $colors['distinct_mixes'].' mixes sold'],
        ['label' => 'Mixed at counter', 'value' => $colors['custom_share'].'%',                            'delta' => $d['custom_share'] ?? null,    'hint' => 'share of colour revenue'],
    ]" />

    <div class="rpt-split">
        <div>
            <h3 class="rpt-sub">Factory shades</h3>
            <x-rpt.bars :rows="$colors['factory']" label-key="label" swatch-key="hex" value-key="revenue"
                        empty="No ready-mixed colours sold in this period." />
        </div>
        <div>
            <h3 class="rpt-sub">Custom mixes</h3>
            <x-rpt.bars :rows="$colors['custom']" label-key="label" swatch-key="hex" value-key="revenue"
                        empty="Nothing was mixed at the counter in this period." />
        </div>
    </div>

    @if (! empty($colors['colorants']))
        <h3 class="rpt-sub">Colorant used (ml)</h3>
        <x-rpt.bars :rows="$colors['colorants']" label-key="label" swatch-key="hex" value-key="ml" :money="false"
                    empty="No colorant was poured in this period." />
    @endif

    <x-rpt.note>
        A mixed can is counted once, at the colour the customer asked for. The base
        and the colourants poured into it are not listed here as separate sellers.
        Colorant used is millilitres poured into tint recipes (per can, times cans) —
        colorant is not stocked, so this is what to reorder by.
    </x-rpt.note>
</x-rpt.section>
