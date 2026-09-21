@props(['hex' => null])

{{-- The swatch shows the paint colour itself, which is the subject of the row,
     not an encoding of its value — the bar length carries the number. A photo
     or a stored hex is a screen preview, never a colour measurement. --}}
@if($hex)
    <span class="rpt-swatch" style="background:{{ \Illuminate\Support\Str::startsWith($hex, '#') ? $hex : '#'.$hex }}"></span>
@else
    <span class="rpt-swatch rpt-swatch-none" title="No colour"></span>
@endif
