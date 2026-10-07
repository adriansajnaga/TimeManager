@php
    use App\Support\PolishDate;
@endphp

<table width="100%">
    <tr>
        @if ($logo)
            <td style="width: 45%; vertical-align: middle;"><img src="{{ $logo }}" style="max-height: 22mm; max-width: 75mm;" alt=""></td>
        @endif
        <td class="company" @if ($logo) style="text-align: right; vertical-align: middle;" @endif>
            <div class="name">{{ $company->name }}</div>
            {{ trim($company->street.', '.$company->zip.' '.$company->city, ', ') }}<br>
            @if ($company->email) mail: {{ $company->email }}<br> @endif
            @if ($company->website) web: {{ $company->website }}<br> @endif
            @if ($company->phone) tel.: {{ $company->phone }} @endif
        </td>
    </tr>
</table>

<h1>Protokół z pomiarów elektrycznych</h1>
<p style="text-align: right; font-size: 11pt;">Numer: <b>{{ $protocol->number }}</b></p>

<table class="meta" width="100%" style="margin-top: 6mm;">
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
