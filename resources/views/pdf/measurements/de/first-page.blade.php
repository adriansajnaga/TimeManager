{{-- Pierwsza strona formularza „Prüf- und Messprotokoll für elektrische Anlagen” (układ jak w oryginale klienta). --}}
@php
    $x = fn (bool $checked) => $checked ? 'X' : '';
    $date = fn ($value) => $value?->format('d.m.Y') ?? '';
    $reasons = \App\Models\MeasurementProtocol::REASONS;
    $visual = [
        ['Richtige Auswahl der Betriebsmittel', 'Leitungsverlegung', 'Schutzkleinspannung/-Trennung'],
        ['Wärmeerzeugende Betriebsmittel', 'Brandschottung', 'Zusätzlicher Potenzialausgleich'],
        ['Keine Schäden an Betriebsmitteln', 'Schutzisolierung', 'Sicherungseinrichtungen'],
        ['Schutz gegen direktes Berühren', 'Hauptpotenzialausgleich', 'Schutzmaßnahmen mit Schutzleiter'],
        ['Sichere Trennung der Schutz- und Funktionskleinspannungs-Stromkreise von anderen Stromkreisen', 'Zielbezeichnung der Leitungen im Verteiler', 'Dokumentation / Zeichnungen'],
    ];
@endphp

@include('pdf.measurements.de.header')

{{-- Zleceniodawca i dane badania. Prüfer i Tel. Prüfer — puste. --}}
<table class="form">
    <tr>
        <td class="label" style="width: 17%;">Auftraggeber</td>
        <td style="width: 34%;">{{ $client }}</td>
        <td class="label" style="width: 17%;">Datum der Prüfung</td>
        <td>{{ $date($protocol->measured_on) }}</td>
    </tr>
    <tr>
        <td class="label" rowspan="2">Adresse Auftraggeber</td>
        <td rowspan="2">{{ $clientAddress }}</td>
        <td class="label">Prüfer</td>
        <td></td>
    </tr>
    <tr>
        <td class="label">Tel. Prüfer</td>
        <td></td>
    </tr>
    <tr>
        <td class="label">Tel. Auftraggeber</td>
        <td></td>
        <td class="label">Externe Auftragsnummer</td>
        <td>{{ $protocol->external_order }}</td>
    </tr>
    <tr>
        <td class="label">Standort / Bauteil:</td>
        <td>{{ $protocol->place }}</td>
        <td class="label">Interne Auftragsnummer</td>
        <td>{{ $protocol->internal_order }}</td>
    </tr>
</table>

{{-- Grund der Prüfung --}}
<table class="form">
    <tr>
        <td class="label" rowspan="2" style="width: 17%;">Grund der Prüfung</td>
        <td class="item">{{ $reasons['new'] }}</td><td class="box">{{ $x($reason === 'new') }}</td>
        <td class="item">{{ $reasons['change'] }}</td><td class="box">{{ $x($reason === 'change') }}</td>
        <td class="item">{{ $reasons['repeat'] }}</td><td class="box">{{ $x($reason === 'repeat') }}</td>
    </tr>
    <tr>
        <td class="item">{{ $reasons['extension'] }}</td><td class="box">{{ $x($reason === 'extension') }}</td>
        <td class="item">{{ $reasons['repair'] }}</td><td class="box">{{ $x($reason === 'repair') }}</td>
        <td class="item">{{ $reasons['echeck'] }}</td><td class="box">{{ $x($reason === 'echeck') }}</td>
    </tr>
</table>

{{-- Prüfung durchgeführt nach --}}
<table class="form">
    <tr>
        <td class="label" rowspan="2" style="width: 17%;">Prüfung durchgeführt nach:</td>
        <td class="item" style="width: 24%;">DIN VDE 0100 T.600</td><td class="box">{{ $x($standard === '0100') }}</td>
        <td class="item">UVV "Elektrische Anlagen und Betriebsmittel (DGUV V3)"</td><td class="box"></td>
    </tr>
    <tr>
        <td class="item">DIN VDE 0105 T.100</td><td class="box">{{ $x($standard === '0105') }}</td>
        <td></td><td class="box"></td>
    </tr>
</table>

{{-- Netz i Netzform --}}
<table class="form">
    <tr>
        <td class="label" style="width: 8%;">Netz:</td>
        <td style="width: 15%; text-align: center;">{{ $protocol->phase_voltage }} V / {{ $protocol->line_voltage }} V</td>
        <td class="label" style="width: 10%;">Netzform:</td>
        @foreach (['TN-C', 'TN-S', 'TN-C-S', 'TT', 'IT'] as $network)
            <td class="item">{{ $network }}</td><td class="box">{{ $x($protocol->network === $network) }}</td>
        @endforeach
    </tr>
</table>

{{-- Besichtigung: trzy kolumny po 5 pozycji, n.i.O. / i.O. --}}
<table class="form">
    <tr>
        @for ($column = 0; $column < 3; $column++)
            <td class="head" style="width: 23%;">Besichtigung</td><td class="head mark">n.i.O.</td><td class="head mark">i.O.</td>
        @endfor
    </tr>
    @foreach ($visual as $line)
        <tr>
            @foreach ($line as $item)
                <td class="item" style="font-size: {{ mb_strlen($item) > 60 ? 5.8 : 7 }}pt;">{{ $item }}</td>
                <td class="box"></td>
                <td class="box">{{ $x($inspectionOk) }}</td>
            @endforeach
        </tr>
    @endforeach
</table>

{{-- Erprobung i Messung --}}
<table class="form">
    <tr><td class="head" colspan="6">Erprobung</td></tr>
    <tr>
        <td class="item" style="width: 30%;">Rechtsdrehfeld der Drehstromsteckdosen</td><td class="box">{{ $x($checks['rotation']) }}</td>
        <td class="item" style="width: 30%;">Funktion der elektrischen Anlage</td><td class="box">{{ $x($checks['function']) }}</td>
        <td class="item" style="width: 30%;">Drehrichtung der Motoren</td><td class="box"></td>
    </tr>
    <tr>
        <td class="item">Überwachungseinrichtungen</td><td class="box"></td>
        <td class="item">FI-Schutzschalter (RCD)</td><td class="box">{{ $x($checks['rcd']) }}</td>
        <td class="item">Gebäudesystemtechnik</td><td class="box"></td>
    </tr>
    <tr>
        <td class="head" colspan="2">Messung</td>
        <td class="item">Erdungswiderstand</td><td class="box">{{ $x($checks['earthing']) }}</td>
        <td class="item">Zuverlässige Verbindung der Schutzleiter</td><td class="box">{{ $x($checks['pe']) }}</td>
    </tr>
</table>

{{-- Obwody: pierwsza rozdzielnica, 6 wierszy (dalsze na kolejnych stronach) --}}
<table class="circuits">
    <tr>
        <td class="h1" colspan="2">Verteiler Nr:</td>
        <td colspan="17" class="left" style="font-weight: bold;">{{ $board }}</td>
    </tr>
    @include('pdf.measurements.de.circuit-head')
    @include('pdf.measurements.de.circuit-rows')
</table>

{{-- Bemerkung --}}
<table class="form">
    <tr>
        <td style="height: 11mm; vertical-align: top;"><b style="font-size: 8pt;">Bemerkung:</b> {!! nl2br(e((string) $protocol->remarks)) !!}</td>
    </tr>
</table>

{{-- Verwendete Messgeräte: trzy wiersze, wypełniony pierwszy --}}
<table class="form">
    @for ($line = 0; $line < 3; $line++)
        <tr>
            @if ($line === 0)
                <td class="label" rowspan="3" style="width: 15%; font-size: 8.5pt;">Verwendete Messgeräte:</td>
            @endif
            <td style="width: 21%;">Fabrikat: {{ $line === 0 ? $instrument['make'] ?? '' : '' }}</td>
            <td style="width: 21%;">Typ: {{ $line === 0 ? $instrument['model'] ?? '' : '' }}</td>
            <td style="width: 21%;">Serien-Nr.: {{ $line === 0 ? $instrument['serial'] ?? '' : '' }}</td>
            <td>Kalibriert bis: {{ $line === 0 ? $instrument['until'] ?? '' : '' }}</td>
        </tr>
    @endfor
</table>

{{-- Prüfergebnis, Prüfplakette (puste), Nächster Prüftermin --}}
<table class="form">
    <tr>
        <td class="label" colspan="2" style="width: 38%;">Prüfergebnis:</td>
        <td class="label" colspan="2" style="width: 20%;">Prüfplakette angebracht?</td>
        <td class="label">Nächster Prüftermin:</td>
    </tr>
    <tr>
        <td class="item" style="width: 34%; background-color: #d9d9d9;">es wurden <b>keine Mängel</b> festgestellt</td><td class="box" style="width: 4%;">{{ $x(! $defects) }}</td>
        <td class="item" style="width: 16%; background-color: #d9d9d9;">ja</td><td class="box" style="width: 4%;"></td>
        <td rowspan="2" style="text-align: center; font-size: 9pt;">{{ $date($protocol->next_test_on) }}</td>
    </tr>
    <tr>
        <td class="item" style="background-color: #d9d9d9;">es wurden <b>Mängel</b> festgestellt</td><td class="box">{{ $x($defects) }}</td>
        <td class="item" style="background-color: #d9d9d9;">nein</td><td class="box"></td>
    </tr>
</table>

{{-- Prüfer der Fa. … / Kontrolle Auftraggeber (pola klienta — puste) --}}
<table class="form" style="width: 73%;">
    <tr>
        <td class="label" colspan="2" style="width: 55%;">Prüfer der Fa. {{ $company['short'] }}:</td>
        <td class="label" colspan="2">Kontrolle Auftraggeber:</td>
    </tr>
    <tr>
        <td class="item" style="width: 49%; font-size: 6.3pt; background-color: #d9d9d9;">Die Anlage entspricht den anerkannten Regeln der Elektrotechnik</td><td class="box" style="width: 6%;">{{ $x(! $defects) }}</td>
        <td class="item" style="width: 39%; font-size: 6.3pt; background-color: #d9d9d9;">Gemäß Übergabebericht Anlage vollständig übernommen</td><td class="box" style="width: 6%;"></td>
    </tr>
    <tr>
        <td class="item" style="font-size: 6.3pt; background-color: #d9d9d9;">Die Anlage entspricht <b>nicht</b> den anerkannten Regeln der Elektrotechnik</td><td class="box">{{ $x($defects) }}</td>
        <td class="item" style="font-size: 6.3pt; background-color: #d9d9d9;">Zustandsbericht erhalten</td><td class="box"></td>
    </tr>
</table>

@include('pdf.measurements.de.signatures', ['gap' => 4])
