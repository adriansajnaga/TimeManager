@php
    use App\Models\MeasurementCableTest;
    use App\Models\MeasurementCircuit;
    use App\Services\Measurements\Criteria;
    use App\Support\MeasurementInput;
    use App\Support\PolishDate;

    $n = fn (?float $value, int $decimals = 2) => $value === null ? '' : number_format($value, $decimals, ',', ' ');
    $raw = fn ($value) => MeasurementInput::show($value);
    $verdict = fn (?bool $passes) => $passes === null ? '' : ($passes ? 'Pozytywna' : 'Negatywna');

    // Warunki prób — mała linia tuż nad tabelą (część tabeli), nie „wyniki”.
    $conditions = 'Un='.$protocol->phase_voltage.'V/'.$protocol->line_voltage.'V, UL='.$protocol->touch_voltage.'V, ko=1,0, ta='.str_replace('.', ',', (string) (float) $protocol->disconnection_time).'s, Typ sieci = '.$protocol->network;
    $condRow = fn (string $text, int $columns) => '<tr><td colspan="'.$columns.'" style="border: none; text-align: left; font-size: 7pt; padding: 0 0 0.6mm 0;">'.e($text).'</td></tr>';
    $tableTitle = fn (string $text) => '<p style="text-align: center; font-weight: bold; font-size: 8.5pt; margin: 4mm 0 1mm;">'.e($text).'</p>';
    $result = fn (bool $negative) => '<p style="text-align: center; font-size: 9.5pt; margin-top: 5mm;'.($negative ? ' color: #c00; font-weight: bold;' : '').'">Wynik przeprowadzonych prób '.($negative ? 'NEGATYWNY' : 'POZYTYWNY').'</p>';
    $legend = function (array $rows) {
        $html = '<p style="font-weight: bold; font-size: 8pt; margin: 4mm 0 1mm;">Legenda do tabeli:</p><table class="legend">';
        foreach ($rows as $symbol => $text) {
            $html .= '<tr><td style="font-weight: bold; width: 28mm;">'.e($symbol).'</td><td>'.e($text).'</td></tr>';
        }

        return $html.'</table>';
    };
@endphp

{{-- Oględziny --}}
<h2>Oględziny instalacji</h2>
@foreach ($protocol->inspections->groupBy('section') as $section => $items)
    <p class="section">{{ $section }}:</p>
    <table class="grid">
        <tr><th width="6%">Lp.</th><th>Wyszczególnienie</th><th width="32%">Wymagania według normy</th><th width="12%">Zgodność</th></tr>
        @foreach ($items->values() as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="left">{{ $item->item }}</td>
                <td class="small">{!! str_replace(', ', '<br>', e((string) $item->standard)) !!}</td>
                <td class="{{ $item->result === 'non_compliant' ? 'neg' : '' }}">{{ $item->resultLabel() }}</td>
            </tr>
        @endforeach
    </table>
@endforeach
<p style="text-align: center; font-size: 9.5pt; margin-top: 5mm;">Wynik przeprowadzonych oględzin i badań {{ $protocol->inspections->contains('result', 'non_compliant') ? 'NEGATYWNY' : 'POZYTYWNY' }}</p>

{{-- RCD: osobny protokół dla każdej rozdzielnicy --}}
@foreach ($boards->filter(fn ($board) => $board->rcds->isNotEmpty()) as $board)
    <pagebreak />
    @include('pdf.measurements.partials.head', [
        'subtitle' => 'z przeprowadzonych prób działania wyłączników różnicowoprądowych (RCD)',
        'facts' => [
            'Data badania' => e(PolishDate::long($protocol->measured_on)),
            'Lokalizacja' => '<b>'.e($board->name).'</b>',
            'Napięcie zasilania' => $protocol->phase_voltage.'/'.$protocol->line_voltage.' [V]',
            'Ilość przebadanych urządzeń' => (string) $board->rcds->count(),
        ],
    ])
    @php($rcdNegative = false)
    <table class="grid">
        <tr>
            <th rowspan="2" width="5%">Lp.</th><th rowspan="2" width="27%">Opis urządzenia</th><th rowspan="2" width="6%">Typ</th>
            <th>In</th><th>I∆n</th><th>UL</th><th>t rcd</th><th>Ia</th><th>Ud</th><th rowspan="2" width="7%">TEST</th><th rowspan="2" width="10%">Ocena</th>
        </tr>
        <tr><th>[A]</th><th>[mA]</th><th>[V]</th><th>1×I∆n [ms]</th><th>[mA]</th><th>[V]</th></tr>
        @foreach ($board->rcds as $index => $rcd)
            @php($passes = $rcd->passes($protocol->touch_voltage))
            @php($rcdNegative = $rcdNegative || $passes === false)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="left">{{ trim($rcd->model.' – '.$rcd->designation, ' –') }}</td>
                <td>{{ $rcd->type->value }}{{ $rcd->selective ? ' S' : '' }}</td>
                <td>{{ $raw($rcd->rated_current) }}</td>
                <td>{{ $rcd->rated_residual }}</td>
                <td>{{ $protocol->touch_voltage }}</td>
                <td>{{ $raw($rcd->trip_time) }}</td>
                <td>{{ $raw($rcd->trip_current) }}</td>
                <td>{{ $raw($rcd->contact_voltage) }}</td>
                <td>{{ $rcd->test_button ? 'tak' : 'nie' }}</td>
                <td class="{{ $passes === false ? 'neg' : '' }}">{{ $verdict($passes) }}</td>
            </tr>
        @endforeach
    </table>
    {!! $legend([
        'Lp' => 'Liczba porządkowa',
        'Typ' => 'Charakterystyka zabezpieczenia różnicowoprądowego',
        'In' => 'Prąd nominalny zabezpieczenia',
        'I∆n' => 'Różnicowy prąd wyłączający wyrażony w [mA] (znamionowy)',
        'UL' => 'Dopuszczalne napięcie dotykowe bezpieczne',
        't rcd' => 'Zmierzony czas wyłączenia RCD',
        'Ia' => 'Prąd powodujący wyłączenie RCD wyrażony w [mA] (zmierzony)',
        'Ud' => 'Napięcie dotyku (zmierzone)',
        'Ocena' => 'Ocena pomiaru pozytywna, gdy czas i prąd zadziałania mieszczą się w granicach dla typu wyłącznika, Ud ≤ UL oraz gdy naciśnięcie przycisku [TEST] spowodowało wyzwolenie zabezpieczenia RCD',
    ]) !!}
    @include('pdf.measurements.partials.inspection-info')
    {!! $result($rcdNegative) !!}
    @unless ($rcdNegative)
        <p style="text-align: center; font-size: 8.5pt; margin: 1mm 0 0;">Wyłącznik zapewnia szybkie samoczynne wyłączenie zasilania zgodnie z normą PN-HD 60364-6:2016-07</p>
    @endunless
@endforeach

{{-- Pętla zwarcia L-PE i N-PE --}}
@foreach (array_filter(['L-PE' => $hasPoints, 'N-PE' => $hasNpe]) as $loopName => $show)
    @php($npe = $loopName === 'N-PE')
    @php($loopNegative = false)
    <pagebreak />
    @include('pdf.measurements.partials.head', [
        'subtitle' => 'z przeprowadzonych badań ochrony przed porażeniem przez samoczynne wyłączenie – pomiar impedancji pętli zwarcia',
        'measurement' => 'POMIAR POMIĘDZY PRZEWODAMI <u style="font-size: 12pt;">'.$loopName.'</u>',
    ])
    @foreach ($boards as $board)
        @continue(! $board->circuits->contains(fn ($circuit) => $circuit->points->whereNotNull($npe ? 'impedance_npe' : 'impedance')->isNotEmpty()))
        @if ($boards->count() > 1)
            {!! $tableTitle('Rozdzielnica '.$board->name) !!}
        @endif
        <table class="grid">
            {!! $condRow($conditions, 11) !!}
            <tr>
                <th width="5%">Lp.</th><th width="26%">Badany punkt</th><th width="8%">Symbol</th><th width="12%">Zabezpieczenie nr</th><th width="6%">Typ</th><th width="6%">In<br>[A]</th><th width="7%">Ia<br>[A]</th>
                <th width="7%">Zs<br>[Ω]</th><th width="7%">Za<br>[Ω]</th><th width="6%">Ik<br>[A]</th><th width="10%">Ocena</th>
            </tr>
            @php($lp = 0)
            @foreach ($board->circuits as $circuit)
                @php($points = $circuit->points->whereNotNull($npe ? 'impedance_npe' : 'impedance')->values())
                @php($ia = $circuit->tripCurrent($protocol))
                @php($za = Criteria::allowedImpedance($protocol->phase_voltage, $ia))
                @foreach ($points as $index => $point)
                    @php($passes = $point->passes($za, $npe))
                    @php($loopNegative = $loopNegative || $passes === false)
                    <tr>
                        <td>{{ ++$lp }}</td>
                        <td class="left">{{ $point->location ?: $circuit->name }}</td>
                        <td>{{ $point->symbol }}</td>
                        @if ($index === 0)
                            <td rowspan="{{ $points->count() }}">{{ $circuit->number }}</td>
                            <td rowspan="{{ $points->count() }}">{{ $circuit->protection_type?->label() }}</td>
                            <td rowspan="{{ $points->count() }}">{{ $raw($circuit->protection_current) }}</td>
                            <td rowspan="{{ $points->count() }}">{{ $n($ia, 0) }}</td>
                        @endif
                        <td>{{ $raw($npe ? $point->impedance_npe : $point->impedance) }}</td>
                        @if ($index === 0)
                            <td rowspan="{{ $points->count() }}">{{ $n($za) }}</td>
                        @endif
                        <td>{{ $n($point->shortCircuitCurrent($protocol, $npe), 0) }}</td>
                        <td class="{{ $passes === false ? 'neg' : '' }}">{{ $verdict($passes) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    @endforeach
    {!! $legend(array_filter([
        'Lp' => 'Liczba porządkowa',
        'Badany punkt' => 'Rodzaj badanego punktu',
        'Symbol' => 'Symbol badanego punktu (gniazdo G, oświetlenie O, faza L)',
        'Zabezpieczenie nr' => 'Numer zabezpieczenia w rozdzielnicy',
        'Typ' => 'Charakterystyka zabezpieczenia nadmiarowo-prądowego',
        'In' => 'Prąd nominalny zabezpieczenia nadmiarowo-prądowego',
        'Ia' => 'Prąd powodujący wyzwolenie zabezpieczenia nadmiarowo-prądowego',
        'Zs' => 'Zmierzona impedancja pętli zwarcia',
        'Za' => 'Wartość wymagana impedancji pętli zwarcia Za = Uo/Ia',
        'Ik' => 'Prąd zwarcia wyliczony Ik = Uo/Zs',
    ])) !!}
    {!! $result($loopNegative) !!}
@endforeach

{{-- WLZ: pętla zwarcia odcinka zasilającego --}}
@if ($hasSupply)
    <pagebreak />
    @include('pdf.measurements.partials.head', ['subtitle' => 'Pomiar impedancji pętli zwarcia'])
    @php($supplyNegative = false)
    @foreach ($supply as $board)
        @foreach ($board->circuits as $circuit)
            @php($points = $circuit->points->whereNotNull('impedance')->values())
            @continue($points->isEmpty())
            @php($ia = $circuit->tripCurrent($protocol))
            @php($za = Criteria::allowedImpedance($protocol->phase_voltage, $ia))
            {!! $tableTitle($board->name.' – od strony rozdzielni elektrycznej') !!}
            <table class="grid">
                {!! $condRow($conditions, 9) !!}
                <tr><th width="6%">Lp.</th><th width="18%">Badany odcinek</th><th width="9%">Typ</th><th width="10%">In [A]</th><th width="10%">Ia [A]</th><th width="11%">Zs [Ω]</th><th width="11%">Za [Ω]</th><th width="11%">Ik [A]</th><th width="14%">Ocena</th></tr>
                @foreach ($points as $index => $point)
                    @php($passes = $point->passes($za))
                    @php($supplyNegative = $supplyNegative || $passes === false)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $point->symbol }}</td>
                        @if ($index === 0)
                            <td rowspan="{{ $points->count() }}">{{ $circuit->protection_type?->label() }}</td>
                            <td rowspan="{{ $points->count() }}">{{ $raw($circuit->protection_current) }}</td>
                            <td rowspan="{{ $points->count() }}">{{ $n($ia, 0) }}</td>
                        @endif
                        <td>{{ $raw($point->impedance) }}</td>
                        @if ($index === 0)
                            <td rowspan="{{ $points->count() }}">{{ $n($za) }}</td>
                        @endif
                        <td>{{ $point->isLineToLine() ? '–' : $n($point->shortCircuitCurrent($protocol), 0) }}</td>
                        <td class="{{ $passes === false ? 'neg' : '' }}">{{ $verdict($passes) }}</td>
                    </tr>
                @endforeach
            </table>
        @endforeach
    @endforeach
    {!! $legend([
        'Lp' => 'Liczba porządkowa',
        'Badany odcinek' => 'Rodzaj badanego obwodu lub odcinka',
        'Typ' => 'Charakterystyka zabezpieczenia nadmiarowo-prądowego',
        'In' => 'Prąd nominalny zabezpieczenia nadmiarowo-prądowego',
        'Ia' => 'Prąd powodujący wyzwolenie zabezpieczenia nadmiarowo-prądowego',
        'Zs' => 'Zmierzona impedancja pętli zwarcia',
        'Za' => 'Wartość wymagana impedancji pętli zwarcia Za = Uo/Ia',
        'Ik' => 'Prąd zwarcia wyliczony Ik = Uo/Zs',
    ]) !!}
    {!! $result($supplyNegative) !!}
@endif

{{-- Izolacja kabli --}}
@if ($protocol->cableTests->isNotEmpty())
    <pagebreak />
    @include('pdf.measurements.partials.head', ['subtitle' => 'z przeprowadzonych badań stanu izolacji'])
    @php($cableNegative = false)
    @foreach ($protocol->cableTests as $cable)
        @php($pairs = array_values(array_filter(MeasurementCableTest::PAIRS, fn ($pair) => filled($cable->values[$pair] ?? null))))
        @continue($pairs === [])
        {!! $tableTitle('BADANIE REZYSTANCJI IZOLACJI – '.mb_strtoupper($cable->name)) !!}
        <table class="grid">
            {!! $condRow('Uiso='.$cable->test_voltage.'V', 9) !!}
            <tr><th>Lp.</th><th>Badany obwód/odcinek</th><th>Przewód</th><th>Przekrój<br>[qmm]</th><th>l<br>[m]</th><th>t<br>[°C]</th><th>Rs<br>[MΩ]</th><th>Ra<br>[MΩ]</th><th>Ocena</th></tr>
            @foreach ($pairs as $index => $pair)
                @php($passes = $cable->passes($pair))
                @php($cableNegative = $cableNegative || $passes === false)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $pair }}</td>
                    @if ($index === 0)
                        <td rowspan="{{ count($pairs) }}">{{ $cable->cable_type }}</td>
                        <td rowspan="{{ count($pairs) }}">{{ $cable->cross_section }}</td>
                        <td rowspan="{{ count($pairs) }}">{{ $raw($cable->length) }}</td>
                        <td rowspan="{{ count($pairs) }}">{{ $raw($cable->temperature) }}</td>
                    @endif
                    <td>{{ $cable->values[$pair] }}</td>
                    @if ($index === 0)
                        <td rowspan="{{ count($pairs) }}">{{ $n((float) $cable->limit) }}</td>
                    @endif
                    <td class="{{ $passes === false ? 'neg' : '' }}">{{ $verdict($passes) }}</td>
                </tr>
            @endforeach
        </table>
    @endforeach
    {!! $legend([
        'Lp' => 'Liczba porządkowa',
        'Obwód/odcinek' => 'Rodzaj badanego obwodu lub odcinka',
        'Przewód' => 'Rodzaj badanego przewodu',
        'Przekrój' => 'Przekrój badanego przewodu oraz ilość żył',
        'l' => 'Długość badanego odcinka',
        't' => 'Temperatura podczas pomiaru',
        'Rs' => 'Zmierzona wartość rezystancji izolacji',
        'Ra' => 'Wymagana wartość rezystancji izolacji',
        'Uiso' => 'Napięcie probiercze',
    ]) !!}
    @include('pdf.measurements.partials.inspection-info', ['cables' => true])
    {!! $result($cableNegative) !!}
@endif

{{-- Izolacja obwodów — każda rozdzielnica na osobnej stronie (nagłówek, legenda, wynik) --}}
@if ($hasInsulation)
    @foreach ($boards as $board)
        @php($circuits = $board->circuits->filter(fn ($circuit) => ! empty($circuit->insulation))->values())
        @continue($circuits->isEmpty())
        <pagebreak />
        @include('pdf.measurements.partials.head', ['subtitle' => 'z przeprowadzonych badań stanu izolacji przewodów'])
        @php($insulationNegative = false)
        @php($voltages = $circuits->pluck('insulation_voltage')->unique()->implode('V / '))
        @if ($boards->count() > 1)
            {!! $tableTitle('Rozdzielnica '.$board->name) !!}
        @endif
        <table class="grid">
            {!! $condRow('Uiso='.$voltages.'V', 7 + count(MeasurementCircuit::REPORT_PAIRS)) !!}
            <tr>
                <th rowspan="2">Lp.</th><th rowspan="2">Obwód</th><th rowspan="2">Nr</th><th rowspan="2">Przewód</th><th rowspan="2">Uiso<br>[V]</th><th rowspan="2">Ra<br>[MΩ]</th>
                <th colspan="{{ count(MeasurementCircuit::REPORT_PAIRS) }}">Rs [MΩ]</th><th rowspan="2">Ocena</th>
            </tr>
            <tr>
                @foreach (MeasurementCircuit::REPORT_PAIRS as $pair)
                    <th style="font-size: 6.5pt;">{{ $pair }}</th>
                @endforeach
            </tr>
            @foreach ($circuits as $index => $circuit)
                @php($passes = $circuit->insulationPasses())
                @php($insulationNegative = $insulationNegative || $passes === false)
                {{-- Obwody jednofazowe: L-N/L-PE w kolumnach swojej fazy (L1, L2 albo L3). --}}
                @php($readings = $circuit->reportReadings())
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="left">{{ $circuit->name }}</td>
                    <td>{{ $circuit->number }}</td>
                    <td>{{ $circuit->cable }}</td>
                    <td>{{ $circuit->insulation_voltage }}</td>
                    <td>≥ {{ $n(Criteria::requiredInsulation($circuit->insulation_voltage), 1) }}</td>
                    @foreach (MeasurementCircuit::REPORT_PAIRS as $pair)
                        <td>{{ $readings[$pair] ?? '' }}</td>
                    @endforeach
                    <td class="{{ $passes === false ? 'neg' : '' }}">{{ $verdict($passes) }}</td>
                </tr>
            @endforeach
        </table>
        {!! $legend([
            'Lp' => 'Liczba porządkowa',
            'Obwód' => 'Nazwa obwodu / pomieszczenia',
            'Nr' => 'Numer zabezpieczenia w rozdzielnicy',
            'Przewód' => 'Rodzaj i przekrój przewodu',
            'Uiso' => 'Napięcie probiercze',
            'Ra' => 'Wymagana wartość rezystancji izolacji',
            'Rs' => 'Zmierzona wartość rezystancji izolacji pomiędzy żyłami',
        ]) !!}
        @include('pdf.measurements.partials.inspection-info')
        {!! $result($insulationNegative) !!}
    @endforeach
@endif

{{-- Ciągłość przewodów ochronnych --}}
@if ($protocol->continuities->isNotEmpty())
    <pagebreak />
    @include('pdf.measurements.partials.head', ['subtitle' => 'z przeprowadzonych badań ciągłości przewodów ochronnych i połączeń wyrównawczych'])
    @php($continuityNegative = false)
    <table class="grid">
        <tr><th width="6%">Lp.</th><th>Badane połączenie</th><th width="14%">R [Ω]</th><th width="20%">Wartość dopuszczalna [Ω]</th><th width="14%">Ocena</th></tr>
        @foreach ($protocol->continuities as $index => $row)
            @php($passes = $row->passes())
            @php($continuityNegative = $continuityNegative || $passes === false)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="left">{{ $row->name }}</td>
                <td>{{ $raw($row->resistance) }}</td>
                <td>{{ ($limitValue = $row->limitValue()) === null ? '–' : number_format($limitValue, 2, ',', '') }}</td>
                <td class="{{ $passes === false ? 'neg' : '' }}">{{ $verdict($passes) }}</td>
            </tr>
        @endforeach
    </table>
    {!! $legend([
        'Lp' => 'Liczba porządkowa',
        'Badane połączenie' => 'Przewód ochronny lub połączenie wyrównawcze',
        'R' => 'Zmierzona rezystancja',
        'Wartość dopuszczalna' => 'Dla obwodów R ≤ UL / Ia',
    ]) !!}
    {!! $result($continuityNegative) !!}
@endif

{{-- Uziemienie --}}
@if ($protocol->earthings->isNotEmpty())
    <pagebreak />
    @include('pdf.measurements.partials.head', ['subtitle' => 'z przeprowadzonych badań pomiaru rezystancji uziemienia'])
    @php($earthingNegative = false)
    @php($earthConditions = collect([
        $protocol->weather ? 'Pogoda: '.$protocol->weather : null,
        $protocol->temperature !== null ? 'Temperatura na zewnątrz: '.$raw($protocol->temperature).'°C' : null,
    ])->filter()->implode(', '))
    <table class="grid">
        @if ($earthConditions !== '')
            {!! $condRow($earthConditions, 8) !!}
        @endif
        <tr><th width="6%">Lp.</th><th>Badany punkt</th><th width="12%">Rysunek – strona</th><th width="10%">RE [Ω]</th><th width="8%">Kp</th><th width="12%">RE [Kp] [Ω]</th><th width="10%">Ra [Ω]</th><th width="12%">Ocena</th></tr>
        @foreach ($protocol->earthings as $index => $row)
            @php($passes = $row->passes())
            @php($earthingNegative = $earthingNegative || $passes === false)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="left">{{ $row->name }}</td>
                <td>{{ $row->drawing }}</td>
                <td>{{ $raw($row->resistance) }}</td>
                <td>{{ $raw($row->correction) }}</td>
                <td>{{ $n($row->corrected()) }}</td>
                <td>{{ $raw($row->limit) }}</td>
                <td class="{{ $passes === false ? 'neg' : '' }}">{{ $verdict($passes) }}</td>
            </tr>
        @endforeach
    </table>
    {!! $legend([
        'Lp' => 'Liczba porządkowa',
        'Badany punkt' => 'Rodzaj badanego uziemienia',
        'Rysunek' => 'Strona, na której znajduje się rzut obiektu z naniesionym uziomem',
        'RE' => 'Wartość rezystancji zmierzonej',
        'Kp' => 'Współczynnik korekcyjny',
        'RE [Kp]' => 'Wartość rezystancji zmierzonej po uwzględnieniu współczynnika korekcyjnego',
        'Ra' => 'Wymagana wartość rezystancji uziemienia',
    ]) !!}
    {!! $result($earthingNegative) !!}
@endif

@include('pdf.measurements.criteria')
@include('pdf.measurements.legal')
