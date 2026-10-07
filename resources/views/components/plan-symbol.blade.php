{{--
    Symbol punktu na rzucie (PN-EN 60617): gniazdo ze stykiem ochronnym — łuk z poziomą kreską i przewodem,
    gniazdo trójfazowe — to samo z ukośną kreską i „3”, oświetlenie — kółko z krzyżykiem, punkt — kwadrat.
    Każdy rodzaj ma swój kolor (czerwona jest tylko rozdzielnica); biała obwódka pod kreskami — czytelność na rysunku.
    Rysunek 24×24 skalowany do rodzica; ten sam kształt rysuje PlanImage w PDF.
--}}
@props(['kind' => 'point', 'color' => null, 'rotation' => 0])

@php($color ??= \App\Models\MeasurementMarker::COLORS[$kind] ?? \App\Models\MeasurementMarker::COLORS['point'])

<svg viewBox="0 0 24 24" fill="none" stroke-linecap="butt" {{ $attributes->merge(['class' => 'size-full overflow-visible']) }}>
    @foreach (['#ffffff' => 3.6, $color => 1.8] as $stroke => $width)
        <g stroke="{{ $stroke }}" stroke-width="{{ $width }}" transform="rotate({{ (int) $rotation }} 12 12)">
            @switch($kind)
                @case('socket')
                @case('socket3')
                    <path d="M3.4 23 A8.6 8.6 0 0 1 20.6 23" />
                    <line x1="3" y1="14" x2="21" y2="14" />
                    <line x1="12" y1="1" x2="12" y2="14" />
                    @if ($kind === 'socket3')
                        <line x1="17.7" y1="21.4" x2="25.4" y2="13.5" />
                    @endif
                    @break
                @case('light')
                    <circle cx="12" cy="12" r="9" />
                    <line x1="5.6" y1="5.6" x2="18.4" y2="18.4" />
                    <line x1="18.4" y1="5.6" x2="5.6" y2="18.4" />
                    @break
                @default
                    <rect x="5" y="5" width="14" height="14" />
            @endswitch
        </g>
    @endforeach
    @if ($kind === 'socket3')
        {{-- „3” w obróconym miejscu, ale zawsze pionowo (jak w PDF) --}}
        @php([$tx, $ty] = [12 + (13 * cos(deg2rad($rotation)) - 13.5 * sin(deg2rad($rotation))), 12 + (13 * sin(deg2rad($rotation)) + 13.5 * cos(deg2rad($rotation)))])
        <text x="{{ round($tx, 2) }}" y="{{ round($ty, 2) }}" text-anchor="middle" font-size="8" font-weight="bold" fill="{{ $color }}" stroke="#ffffff" stroke-width="2" paint-order="stroke">3</text>
    @endif
</svg>
