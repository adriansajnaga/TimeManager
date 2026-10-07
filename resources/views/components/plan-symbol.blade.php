{{--
    Symbol punktu na rzucie (jak na planach instalacji): gniazdo — półkole z bolcem ochronnym,
    gniazdo trójfazowe — to samo z trzema kreskami, oświetlenie — kółko z krzyżykiem, punkt — kwadrat.
    Rysunek 24×24 skalowany do rozmiaru rodzica; ten sam kształt rysuje PlanImage w PDF.
--}}
@props(['kind' => 'point', 'color' => '#dc2626'])

<svg viewBox="0 0 24 24" fill="none" stroke="{{ $color }}" stroke-width="2.2" stroke-linecap="round" {{ $attributes->merge(['class' => 'size-full overflow-visible']) }}>
    @switch($kind)
        @case('socket')
        @case('socket3')
            <path d="M4 13 A8 8 0 0 1 20 13 Z" fill="#ffffff" />
            <line x1="3" y1="13" x2="21" y2="13" />
            <line x1="6" y1="3.5" x2="18" y2="3.5" />
            <line x1="12" y1="13" x2="12" y2="23" />
            @if ($kind === 'socket3')
                <g stroke-width="1.6">
                    <line x1="9.5" y1="16.5" x2="14.5" y2="14.5" />
                    <line x1="9.5" y1="19.5" x2="14.5" y2="17.5" />
                    <line x1="9.5" y1="22.5" x2="14.5" y2="20.5" />
                </g>
            @endif
            @break
        @case('light')
            <circle cx="12" cy="12" r="9" fill="#ffffff" />
            <line x1="5.6" y1="5.6" x2="18.4" y2="18.4" />
            <line x1="18.4" y1="5.6" x2="5.6" y2="18.4" />
            @break
        @default
            <rect x="5" y="5" width="14" height="14" fill="#ffffff" />
    @endswitch
</svg>
