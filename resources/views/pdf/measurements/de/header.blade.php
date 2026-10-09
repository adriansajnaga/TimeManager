{{-- Nagłówek formularza: firma klienta (z kartoteki kontrahenta), logo, tytuł z numerem protokołu. --}}
<table width="100%" style="border-collapse: collapse;">
    <tr>
        <td style="vertical-align: top;">
            <div class="company-name">{{ $company['name'] }}</div>
            @if ($company['address'] !== '')<div class="company-line">{{ $company['address'] }}</div>@endif
            @if ($company['contact'] !== '')<div class="company-line">{{ $company['contact'] }}</div>@endif
        </td>
        <td style="width: 32%; text-align: right; vertical-align: top;">
            @if ($logo)
                <img src="{{ $logo }}" style="height: 15mm;" alt="">
            @endif
        </td>
    </tr>
</table>
<div style="font-size: 14.5pt; font-weight: bold; margin: 1.5mm 0 1mm;">Prüf- und Messprotokoll für elektrische Anlagen Nr.: <u style="font-weight: normal; font-size: 11pt;">&nbsp;&nbsp;{{ $protocol->number }}&nbsp;&nbsp;&nbsp;&nbsp;</u></div>
