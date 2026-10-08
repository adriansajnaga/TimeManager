@php
    use App\Support\PolishDate;
@endphp

{{-- Jak w dotychczasowym wzorze: dane firmy po lewej, logo po prawej, linia pod nagłówkiem.
     Bez tabeli — mPDF pomniejszał tabelę z logo (liczy szerokość z oryginalnego obrazu). --}}
<div style="border-bottom: 0.3mm solid #000; padding-bottom: 1.5mm;">
    @if ($logo)
        <div style="float: right; width: 45%; text-align: right;"><img src="{{ $logo }}" style="height: 14mm;" alt=""></div>
    @endif
    <div style="font-size: 18pt; font-weight: bold;">{{ $company->name }}</div>
    <div style="font-size: 12pt;">
        {{ trim($company->street.', '.$company->zip.' '.$company->city, ', ') }}<br>
        @if ($company->email) mail: {{ $company->email }}<br> @endif
        @if ($company->website) web: {{ $company->website }}<br> @endif
        @if ($company->phone) tel.: {{ $company->phone }} @endif
    </div>
    <div style="clear: both;"></div>
</div>

{{-- Tytuł między liniami, numer po prawej — jak w dotychczasowym wzorze. --}}
<table width="100%" style="border-collapse: collapse; margin-top: 4mm;">
    <tr><td style="border-top: 0.3mm solid #000; padding-top: 2mm; text-align: center; font-size: 24pt; font-weight: bold;">Protokół z pomiarów elektrycznych</td></tr>
    <tr><td style="border-bottom: 0.3mm solid #000; padding-bottom: 1mm; text-align: right; font-size: 10.5pt;">Numer: <b>{{ $protocol->number }}</b></td></tr>
</table>

<table class="meta title-meta" width="100%" style="margin-top: 4mm;">
    <tr><td class="label">Wykonawca:</td><td class="value">{{ $company->name }}</td></tr>
    <tr><td class="label">Miejsce pomiaru:</td><td class="value">{{ $protocol->place }}</td></tr>
    @if ($protocol->investor)
        <tr><td class="label">Inwestor:</td><td class="value">{{ $protocol->investor }}</td></tr>
    @endif
    @if ($protocol->description)
        <tr><td class="label">Opis:</td><td class="value">{{ $protocol->description }}</td></tr>
    @endif
    <tr><td class="label">Data pomiaru:</td><td class="value">{{ PolishDate::long($protocol->measured_on) }}</td></tr>
    <tr><td class="label">Urządzenie pomiarowe:</td><td class="value">{{ $instrumentLabel }}</td></tr>
    <tr>
        <td class="label">Zawartość zbioru:</td>
        <td>
            @foreach ($sections as $section)
                - {{ $section }}<br>
            @endforeach
        </td>
    </tr>
    <tr>
        <td class="label">Pomiary wykonał:</td>
        <td>
            @foreach ($protocol->performers as $performer)
                - <b>{{ $performer->name }}</b><br>
                @foreach ($performer->certificateLines() as $line)
                    &nbsp;&nbsp;- {{ $line }}<br>
                @endforeach
            @endforeach
        </td>
    </tr>
    @if ($protocol->remarks)
        <tr><td class="label">Uwagi:</td><td>{!! nl2br(e($protocol->remarks)) !!}</td></tr>
    @endif
    @if ($protocol->verdict)
        <tr><td class="label">Orzeczenie:</td><td><b>{!! nl2br(e($protocol->verdict)) !!}</b></td></tr>
    @endif
</table>

<table width="100%" style="margin-top: 14mm;">
    <tr>
        <td>@if ($protocol->next_test_on) Uwaga: Termin następnych badań – <b>{{ PolishDate::monthYear($protocol->next_test_on) }}</b> @endif</td>
        <td style="text-align: right;">{{ $company->issue_place ?: $company->city }}, dnia {{ PolishDate::long($protocol->measured_on) }}</td>
    </tr>
</table>
