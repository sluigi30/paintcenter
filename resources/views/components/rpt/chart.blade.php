@props(['id', 'spec', 'height' => 260, 'caption' => null])

{{-- wire:ignore keeps Livewire morphs off the canvas Chart.js owns; the spec
     rides in a data attribute so the print document can draw the same chart
     with no Livewire and no second copy of the configuration. --}}
<figure class="rpt-figure" wire:ignore>
    <div class="rpt-canvas-wrap" style="height:{{ $height }}px">
        <canvas id="{{ $id }}" class="rpt-chart" data-chart="{{ json_encode($spec) }}"></canvas>
    </div>
    @if($caption)
        <figcaption class="rpt-figcaption">{{ $caption }}</figcaption>
    @endif
</figure>
