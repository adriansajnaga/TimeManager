{{--
    Szyny rozdzielnicy: $rows (BoardLayout::resolved), $rail (moduły na szynie), $module (mm na moduł).
    Każda szyna to SVG w milimetrach — dokładna skala, puste szyny i wolne moduły zachowują wymiary.
--}}
@foreach ($rows as $items)
    @php($slots = max($rail, array_sum(array_column($items, 'w'))))
    <div style="text-align: center; margin-top: 4mm; page-break-inside: avoid;">
        <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Services\Measurements\BoardSvg::rail($items, $rail, $module)) }}"
            style="width: {{ round($slots * $module, 2) }}mm;" />
    </div>
@endforeach
