{{-- Rzut z punktami pomiarowymi — legenda po niemiecku (wzór dla klienta z DE). --}}
<table width="100%" style="border-collapse: collapse; border-bottom: 0.3mm solid #222; margin-bottom: 3mm;">
    <tr>
        <td style="font-size: 12pt; font-weight: bold;">{{ $title }}</td>
        <td style="text-align: right; font-size: 8pt;">Prüf- und Messprotokoll Nr.: <b>{{ $protocol->number }}</b><br>{{ $protocol->place }}</td>
    </tr>
</table>
@if ($caption)
    <div style="font-size: 8pt; color: #555; margin-bottom: 2mm;">{{ $caption }}</div>
@endif
<div style="text-align: center;">
    <img src="{{ $path }}" style="max-width: 186mm; max-height: {{ empty($legend) ? 235 : 200 - 5 * count($legend) }}mm;">
</div>

@if (! empty($legend))
    <table style="margin-top: 3mm; font-size: 8pt;">
        <tr><td colspan="2" style="font-weight: bold; padding-bottom: 1mm;">Legende:</td></tr>
        @foreach ($legend as $item)
            <tr>
                <td style="width: 14mm; text-align: center; padding: 0.6mm 0;">
                    @if ($item === 'board')
                        <span style="background-color: #dc2626; color: #fff; font-weight: bold; border: 0.3mm solid #18181b; padding: 0 1.5mm;">UV</span>
                    @elseif ($item === 'bonding')
                        <span style="background-color: {{ \App\Models\MeasurementMarker::BONDING_COLOR }}; color: #fff; font-weight: bold; border: 0.3mm solid #18181b; padding: 0 1.5mm;">HES</span>
                    @else
                        <img src="{{ $symbols[$item] }}" style="width: 5mm; height: 5mm;" />
                    @endif
                </td>
                <td style="padding: 0.6mm 2mm;">
                    @if ($item === 'board')
                        Verteiler
                    @elseif ($item === 'bonding')
                        Haupterdungsschiene (HES)
                    @else
                        {{ __(\App\Models\MeasurementMarker::LEGEND[$item], [], 'de') }}
                    @endif
                </td>
            </tr>
        @endforeach
        <tr>
            <td style="text-align: center; padding: 0.6mm 0;"><span style="background-color: #71717a; color: #fff; font-weight: bold; padding: 0 1.5mm;">1</span></td>
            <td style="padding: 0.6mm 2mm;">Nummer des Messpunkts im Grundriss</td>
        </tr>
    </table>
@endif
