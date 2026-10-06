{{--
    Szyny rozdzielnicy: $rows (BoardLayout::resolved), $rail (moduły na szynie), $module (mm na moduł),
    $descHeight (mm na pionowy opis nad modułem). Wolne moduły — osobne puste kratki.
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
            @foreach ($items as $item)
                <td style="width: {{ $item['w'] * $module }}mm; height: {{ $module >= 15 ? 12 : 11 }}mm; text-align: center; vertical-align: middle; font-size: {{ $module >= 15 ? 8.5 : 7.5 }}pt; font-weight: bold;
                    border: {{ $item['t'] === 'gap' ? '0.2mm dashed #999' : '0.3mm solid #333' }};
                    background-color: {{ $item['t'] === 'circuit' || $item['t'] === 'gap' ? '#ffffff' : '#d9d9d9' }};">
                    {{ $item['label'] }}
                    @if ($item['sub'] !== '')
                        <br><span style="font-size: {{ $module >= 15 ? 6.5 : 5.5 }}pt; font-weight: normal;">{{ $item['sub'] }}</span>
                    @endif
                </td>
            @endforeach
            @for ($i = 0; $i < $free; $i++)
                <td style="width: {{ $module }}mm; border: 0.3mm solid #333;"></td>
            @endfor
        </tr>
    </table>
@endforeach
