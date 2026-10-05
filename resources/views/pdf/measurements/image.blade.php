@if ($title)
    <h2>{{ $title }}</h2>
@endif
@if ($caption)
    <h3>{{ $caption }}</h3>
@endif
<div style="text-align: center; margin-top: 4mm;">
    <img src="{{ $path }}" style="max-width: 180mm; max-height: 200mm;">
</div>
