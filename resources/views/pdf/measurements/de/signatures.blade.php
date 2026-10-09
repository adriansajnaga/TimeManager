{{-- Podpisy (puste linie do podpisu), jak w formularzu. --}}
<table width="100%" class="sign" style="margin-top: {{ $gap ?? 8 }}mm; border-collapse: collapse;">
    <tr>
        <td style="width: 37%; border-bottom: 0.3mm solid #222; height: 7mm;"></td>
        <td style="width: 4%;"></td>
        <td style="width: 31%; border-bottom: 0.3mm solid #222;"></td>
        <td rowspan="2" style="text-align: right; vertical-align: bottom;">
        </td>
    </tr>
    <tr>
        <td style="padding-top: 1mm;">Datum und Unterschrift {{ $company['name'] }}</td>
        <td></td>
        <td style="padding-top: 1mm;">Datum und Unterschrift Auftraggeber</td>
    </tr>
</table>
