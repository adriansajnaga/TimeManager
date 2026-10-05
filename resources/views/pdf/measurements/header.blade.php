<table class="header" width="100%" style="border-bottom: 0.3mm solid #333;">
    <tr>
        <td width="22%">Numer protokołu:</td>
        <td><b>{{ $protocol->number }}</b></td>
        <td width="18%" style="text-align: right;">Strona: <b>{PAGENO}</b></td>
    </tr>
    <tr><td>Wykonawca:</td><td colspan="2">{{ $company->name }}</td></tr>
    <tr><td>Miejsce pomiaru:</td><td colspan="2">{{ $protocol->place }}</td></tr>
    <tr><td>Miernik:</td><td colspan="2">{{ $instrumentLabel }}</td></tr>
</table>
