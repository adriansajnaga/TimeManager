@if ($title)
    <h2>{{ $title }}</h2>
@endif
@if ($caption)
    <h3>{{ $caption }}</h3>
@endif
<div style="text-align: center; margin-top: 4mm;">
    <img src="{{ $path }}" style="max-width: 180mm; max-height: 190mm;">
</div>

{{-- Legenda rzutu: tylko symbole, które są na rysunku (te same co na ekranie, components/plan-symbol). --}}
@if (! empty($legend))
    <table style="margin-top: 3mm; font-size: 8pt;">
        <tr><td colspan="2" style="font-weight: bold; padding-bottom: 1mm;">Legenda:</td></tr>
        @foreach ($legend as $item)
            <tr>
                <td style="width: 14mm; text-align: center; padding: 0.6mm 0;">
                    @if ($item === 'board')
                        <span style="background-color: #dc2626; color: #fff; font-weight: bold; border: 0.3mm solid #18181b; padding: 0 1.5mm;">R1</span>
                    @elseif ($item === 'bonding')
                        <span style="background-color: {{ \App\Models\MeasurementMarker::BONDING_COLOR }}; color: #fff; font-weight: bold; border: 0.3mm solid #18181b; padding: 0 1.5mm;">GSW</span>
                    @else
                        <img src="{{ $symbols[$item] }}" style="width: 5mm; height: 5mm;" />
                    @endif
                </td>
                <td style="padding: 0.6mm 2mm;">
                    @if ($item === 'board')
                        Rozdzielnica
                    @elseif ($item === 'bonding')
                        Główna szyna wyrównawcza (GSW)
                    @else
                        {{ __(\App\Models\MeasurementMarker::LEGEND[$item], [], 'pl') }}
                    @endif
                </td>
            </tr>
        @endforeach
        <tr>
            <td style="text-align: center; padding: 0.6mm 0;"><span style="background-color: #71717a; color: #fff; font-weight: bold; padding: 0 1.5mm;">1</span></td>
            <td style="padding: 0.6mm 2mm;">Numer punktu pomiarowego na rzucie</td>
        </tr>
    </table>
@endif
