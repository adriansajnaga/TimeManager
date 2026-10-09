{{-- Podpisy (puste linie do podpisu) i znak grupy HPM, jak w formularzu. --}}
<table width="100%" class="sign" style="margin-top: {{ $gap ?? 8 }}mm; border-collapse: collapse;">
    <tr>
        <td style="width: 37%; border-bottom: 0.3mm solid #222; height: 7mm;"></td>
        <td style="width: 4%;"></td>
        <td style="width: 31%; border-bottom: 0.3mm solid #222;"></td>
        <td rowspan="2" style="text-align: right; vertical-align: bottom;">
            @if ($company['hpm'])
                <table style="border-collapse: collapse; margin-left: auto;">
                    <tr>
                        <td style="background-color: #111; color: #fff; font-weight: bold; font-size: 10pt; padding: 2.5mm 1.6mm; border-radius: 6mm;">HPM</td>
                        <td style="font-weight: bold; font-size: 9pt; line-height: 1.05; padding-left: 1.5mm; color: #111;">DIE<br>HANDWERKS<br>GRUPPE</td>
                    </tr>
                </table>
            @endif
        </td>
    </tr>
    <tr>
        <td style="padding-top: 1mm;">Datum und Unterschrift {{ $company['name'] }}</td>
        <td></td>
        <td style="padding-top: 1mm;">Datum und Unterschrift Auftraggeber</td>
    </tr>
</table>
