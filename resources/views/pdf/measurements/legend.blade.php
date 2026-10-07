{{-- Legenda do rozdzielnicy: elewacja w skali 1:1, gdy się mieści ($module z kontrolera), i pionowa tabela obwodów. --}}
@php
    $items = collect($rows)->flatten(1)->filter(fn ($item) => $item['t'] !== 'gap' && $item['label'] !== '');
@endphp
<table width="100%" style="border-bottom: 0.4mm solid #333; margin-bottom: 2mm;">
    <tr>
        <td style="font-size: 16pt; font-weight: bold;">Rozdzielnica {{ $board->name }}</td>
        <td style="text-align: right; font-size: 9pt;">{{ $protocol->place }}<br>{{ $company->name }} · {{ $protocol->measured_on->format('d.m.Y') }}</td>
    </tr>
</table>
@if ($module >= 18)
    <p style="font-size: 7pt; color: #666; margin: 0;">Skala 1:1 (moduł 18 mm) — wydrukuj bez dopasowania do strony, wytnij szyny i przyłóż nad aparatami.</p>
@endif

@include('pdf.measurements.partials.rails', ['rows' => $rows, 'rail' => $rail, 'module' => $module])

<pagebreak orientation="portrait" />
<h2 style="text-align: left;">Rozdzielnica {{ $board->name }} — który bezpiecznik od czego</h2>
<table class="grid" style="margin-top: 3mm;">
    <tr><th width="12%">Symbol</th><th>Opis</th><th width="16%">Zabezpieczenie</th></tr>
    @foreach ($items as $item)
        <tr>
            <td><b>{{ $item['label'] }}</b></td>
            <td class="left">{{ $item['desc'] }}</td>
            <td>{{ $item['sub'] }}</td>
        </tr>
    @endforeach
</table>
