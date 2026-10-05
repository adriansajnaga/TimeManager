@php
    use App\Models\MeasurementCableTest;
    use App\Models\MeasurementCircuit;
    use App\Services\Measurements\Criteria;
    use App\Support\MeasurementInput;
    use App\Support\PolishDate;

    $n = fn (?float $value, int $decimals = 2) => $value === null ? '' : number_format($value, $decimals, ',', ' ');
    $raw = fn ($value) => MeasurementInput::show($value);
    $verdict = fn (?bool $passes) => $passes === null ? '' : ($passes ? 'Pozytywna' : 'Negatywna');
    $params = 'Un='.$protocol->phase_voltage.'V/'.$protocol->line_voltage.'V, Ul='.$protocol->touch_voltage.'V, ko=1,0, ta='.str_replace('.', ',', (string) (float) $protocol->disconnection_time).'s, Typ sieci = '.$protocol->network;
    $result = function (bool $negative) {
        return '<p class="result'.($negative ? ' neg' : '').'">- Wynik przeprowadzonych prób '.($negative ? 'NEGATYWNY' : 'POZYTYWNY').'</p>';
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
{!! $result($protocol->inspections->contains('result', 'non_compliant')) !!}

{{-- RCD --}}
@if ($hasRcd)
    <pagebreak />
    <h2>PROTOKÓŁ</h2>
    <h3>z przeprowadzonych prób działania wyłączników różnicowoprądowych (RCD)</h3>
    @php($rcdNegative = false)
    @foreach ($boards->filter(fn ($board) => $board->rcds->isNotEmpty()) as $board)
        <p class="section">Rozdzielnica {{ $board->name }} · Data badania: {{ PolishDate::long($protocol->measured_on) }} · Napięcie zasilania: {{ $protocol->phase_voltage }}/{{ $protocol->line_voltage }} V</p>
        <table class="grid">
            <tr>
                <th width="5%">Lp.</th><th width="27%">Opis urządzenia</th><th width="6%">Typ</th><th width="6%">In<br>[A]</th><th width="7%">I∆n<br>[mA]</th><th width="6%">UL<br>[V]</th>
                <th width="10%">t rcd 1×I∆n<br>[ms]</th><th width="7%">Ia<br>[mA]</th><th width="7%">Ud<br>[V]</th><th width="7%">TEST</th><th width="10%">Ocena</th>
            </tr>
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
    @endforeach
    <table class="legend" style="margin-top: 3mm;">
        <tr><td>I∆n</td><td>Różnicowy prąd wyłączający (znamionowy)</td></tr>
        <tr><td>UL</td><td>Dopuszczalne napięcie dotykowe bezpieczne</td></tr>
        <tr><td>t rcd</td><td>Zmierzony czas wyłączenia RCD przy 1×I∆n</td></tr>
        <tr><td>Ia</td><td>Zmierzony prąd powodujący wyłączenie RCD</td></tr>
        <tr><td>Ud</td><td>Zmierzone napięcie dotyku</td></tr>
        <tr><td>Ocena</td><td>Pozytywna, gdy czas i prąd zadziałania mieszczą się w granicach dla typu wyłącznika, Ud ≤ UL i naciśnięcie przycisku TEST spowodowało wyzwolenie</td></tr>
    </table>
    {!! $result($rcdNegative) !!}
@endif

{{-- Pętla zwarcia L-PE i N-PE --}}
@foreach (array_filter(['L-PE' => $hasPoints, 'N-PE' => $hasNpe]) as $loopName => $show)
    @php($npe = $loopName === 'N-PE')
    @php($loopNegative = false)
    @foreach ($boards as $board)
        @if ($board->circuits->contains(fn ($circuit) => $circuit->points->whereNotNull($npe ? 'impedance_npe' : 'impedance')->isNotEmpty()))
            <pagebreak />
            <h2>PROTOKÓŁ – ROZDZIELNIA {{ $board->name }}</h2>
            <h3>z przeprowadzonych badań ochrony przed porażeniem przez samoczynne wyłączenie – pomiar impedancji pętli zwarcia<br><b>POMIAR POMIĘDZY PRZEWODAMI {{ $loopName }}</b></h3>
            <p class="params">Wyniki przeprowadzonych prób: {{ $params }}</p>
            <table class="grid">
                <tr>
                    <th width="5%">Lp.</th><th width="29%">Badany punkt</th><th width="8%">Symbol</th><th width="9%">Zabezp. nr</th><th width="6%">Typ</th><th width="6%">In<br>[A]</th><th width="7%">Ia<br>[A]</th>
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
        @endif
    @endforeach
    <table class="legend" style="margin-top: 3mm;">
        <tr><td>Symbol</td><td>Symbol badanego punktu naniesiony na rzucie obiektu</td></tr>
        <tr><td>Ia</td><td>Prąd powodujący samoczynne zadziałanie zabezpieczenia w wymaganym czasie</td></tr>
        <tr><td>Zs</td><td>Zmierzona impedancja pętli zwarcia</td></tr>
        <tr><td>Za</td><td>Wymagana impedancja pętli zwarcia Za = Uo/Ia</td></tr>
        <tr><td>Ik</td><td>Prąd zwarcia wyliczony Ik = Uo/Zs</td></tr>
    </table>
    {!! $result($loopNegative) !!}
@endforeach

{{-- WLZ: pętla zwarcia odcinka zasilającego --}}
@if ($hasSupply)
    <pagebreak />
    <h2>PROTOKÓŁ</h2>
    <h3>Pomiar impedancji pętli zwarcia – wewnętrzna linia zasilająca (WLZ)</h3>
    <p class="params">{{ $params }}</p>
    @php($supplyNegative = false)
    @foreach ($supply as $board)
        @foreach ($board->circuits as $circuit)
            @php($points = $circuit->points->whereNotNull('impedance')->values())
            @continue($points->isEmpty())
            @php($ia = $circuit->tripCurrent($protocol))
            @php($za = Criteria::allowedImpedance($protocol->phase_voltage, $ia))
            <p class="section">{{ $board->name }}</p>
            <table class="grid">
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
    {!! $result($supplyNegative) !!}
@endif

{{-- Izolacja kabli --}}
@if ($protocol->cableTests->isNotEmpty())
    <pagebreak />
    <h2>PROTOKÓŁ</h2>
    <h3>z przeprowadzonych badań stanu izolacji kabli</h3>
    @php($cableNegative = false)
    @foreach ($protocol->cableTests as $cable)
        @php($pairs = array_values(array_filter(MeasurementCableTest::PAIRS, fn ($pair) => filled($cable->values[$pair] ?? null))))
        @continue($pairs === [])
        <p class="section">{{ mb_strtoupper($cable->name) }} · Uiso={{ $cable->test_voltage }}V</p>
        <table class="grid">
            <tr><th>Lp.</th><th>Badany odcinek</th><th>Przewód</th><th>Przekrój</th><th>l [m]</th><th>t [°C]</th><th>Rs [MΩ]</th><th>Ra [MΩ]</th><th>Ocena</th></tr>
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
    {!! $result($cableNegative) !!}
@endif

{{-- Izolacja obwodów --}}
@if ($hasInsulation)
    @php($insulationNegative = false)
    @foreach ($boards as $board)
        @php($circuits = $board->circuits->filter(fn ($circuit) => ! empty($circuit->insulation))->values())
        @continue($circuits->isEmpty())
        <pagebreak />
        <h2>PROTOKÓŁ – ROZDZIELNIA {{ $board->name }}</h2>
        <h3>z przeprowadzonych badań stanu izolacji przewodów</h3>
        <table class="grid">
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
                @php($readings = $circuit->insulation ?? [])
                {{-- Obwody jednofazowe: L-N/L-PE w kolumnach L1-N/L1-PE. --}}
                @php($readings = $circuit->phases === 3 ? $readings : array_filter(['L1-N' => $readings['L-N'] ?? null, 'L1-PE' => $readings['L-PE'] ?? null, 'N-PE' => $readings['N-PE'] ?? null]))
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
    @endforeach
    {!! $result($insulationNegative) !!}
@endif

{{-- Ciągłość i uziemienie --}}
@if ($protocol->continuities->isNotEmpty() || $protocol->earthings->isNotEmpty())
    <pagebreak />
    @if ($protocol->continuities->isNotEmpty())
        <h2>PROTOKÓŁ</h2>
        <h3>z przeprowadzonych badań ciągłości przewodów ochronnych i połączeń wyrównawczych</h3>
        @php($continuityNegative = false)
        <table class="grid">
            <tr><th>Lp.</th><th>Badane połączenie</th><th>R [Ω]</th><th>Wartość dopuszczalna [Ω]</th><th>Ocena</th></tr>
            @foreach ($protocol->continuities as $index => $row)
                @php($passes = $row->passes())
                @php($continuityNegative = $continuityNegative || $passes === false)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="left">{{ $row->name }}</td>
                    <td>{{ $raw($row->resistance) }}</td>
                    <td>{{ $raw($row->limit) ?: '–' }}</td>
                    <td class="{{ $passes === false ? 'neg' : '' }}">{{ $verdict($passes) }}</td>
                </tr>
            @endforeach
        </table>
        {!! $result($continuityNegative) !!}
    @endif

    @if ($protocol->earthings->isNotEmpty())
        <h2 style="margin-top: 8mm;">PROTOKÓŁ</h2>
        <h3>z przeprowadzonych badań pomiaru rezystancji uziemienia</h3>
        <p class="params">
            @if ($protocol->weather) Pogoda: {{ $protocol->weather }}@endif
            @if ($protocol->temperature !== null), temperatura na zewnątrz: {{ $raw($protocol->temperature) }}°C @endif
        </p>
        @php($earthingNegative = false)
        <table class="grid">
            <tr><th>Lp.</th><th>Badany punkt</th><th>Rysunek – strona</th><th>RE [Ω]</th><th>Kp</th><th>RE·Kp [Ω]</th><th>Ra [Ω]</th><th>Ocena</th></tr>
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
        {!! $result($earthingNegative) !!}
    @endif
@endif

@include('pdf.measurements.criteria')
@include('pdf.measurements.legal')
