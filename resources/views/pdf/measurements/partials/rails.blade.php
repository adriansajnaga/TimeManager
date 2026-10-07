{{--
    Szyny rozdzielnicy: $rows (BoardLayout::resolved), $rail (moduły na szynie), $module (mm na moduł),
    $descHeight (mm na opis nad modułem), $boxHeight (mm wysokości modułu). Wolne moduły — puste zaślepki.
--}}
@foreach ($rows as $items)
    @php($used = array_sum(array_column($items, 'w')))
    @php($free = max(0, $rail - $used))
    <table style="border-collapse: collapse; margin: 4mm auto 0; page-break-inside: avoid;">
        <tr>
            @foreach ($items as $item)
                {{-- Wąskie moduły: opis pionowo; szerokie (od 2 modułów: RCD, F0, WG): poziomo, zawinięty. --}}
                @if ($item['w'] >= 2)
                    <td style="width: {{ $item['w'] * $module }}mm; height: {{ $descHeight }}mm; vertical-align: bottom; text-align: center; font-size: {{ $module >= 15 ? 7.5 : 6.5 }}pt; padding: 0 1mm 1mm 1mm;">{{ $item['desc'] }}</td>
                @else
                    <td text-rotate="90" style="width: {{ $item['w'] * $module }}mm; height: {{ $descHeight }}mm; vertical-align: bottom; text-align: left; font-size: {{ $module >= 15 ? 7.5 : 6.5 }}pt; padding: 0 0 1mm 0;">{{ $item['desc'] }}</td>
                @endif
            @endforeach
            @for ($i = 0; $i < $free; $i++)
                <td style="width: {{ $module }}mm;"></td>
            @endfor
        </tr>
        <tr>
            {{-- Moduł jak aparat: numer u góry, dźwigienka, zabezpieczenie na dole. --}}
            @foreach ($items as $item)
                <td style="width: {{ $item['w'] * $module }}mm; height: {{ $boxHeight }}mm; text-align: center; vertical-align: middle; padding: 1mm 0;
                    border: {{ $item['t'] === 'gap' ? '0.2mm dashed #999' : '0.3mm solid #333' }};
                    background-color: {{ $item['t'] === 'circuit' || $item['t'] === 'gap' ? '#ffffff' : '#d9d9d9' }};">
                    @if ($item['t'] !== 'gap')
                        <div style="font-size: {{ $module >= 15 ? 8.5 : 7.5 }}pt; font-weight: bold;">{{ $item['label'] }}</div>
                        <table style="margin: 1.2mm auto; border-collapse: collapse;">
                            <tr><td style="width: {{ min(3.5, $module * 0.22) }}mm; height: {{ $boxHeight * 0.32 }}mm; background-color: #3f3f46; border: none; padding: 0;"></td></tr>
                        </table>
                        <div style="font-size: {{ $module >= 15 ? 6.5 : 5.5 }}pt;">{{ $item['sub'] }}</div>
                    @endif
                </td>
            @endforeach
            @for ($i = 0; $i < $free; $i++)
                <td style="width: {{ $module }}mm; height: {{ $boxHeight }}mm; border: 0.3mm solid #333; background-color: #fafafa;"></td>
            @endfor
        </tr>
    </table>
@endforeach
