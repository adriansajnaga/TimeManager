{{-- Formularz klienta: nagłówek i logo klienta (np. Gärtner Elektrotechnik GmbH). --}}
<table class="layout">
    <tr>
        <td>
            <span class="bold" style="font-size: 10pt;">{{ $client->name }}</span><br>
            <span class="small">
                {{ trim($client->street.' '.$client->zip.' '.$client->city) }}
                @if ($client->phone) &nbsp; {{ $t('phone') }} {{ $client->phone }} @endif
                @if ($client->fax) &nbsp; {{ $t('fax') }} {{ $client->fax }} @endif
                <br>
                {{ trim($client->email.' '.$client->website) }}
            </span>
        </td>
        <td class="right" style="width: 60mm;">
            @if ($clientLogo)
                <img src="{{ $clientLogo }}" style="height: 16mm;" alt="">
            @endif
        </td>
    </tr>
</table>

<div class="spacer"></div>
<h1>{{ $t('stundennachweis') }} ______________</h1>
<div class="spacer"></div>

<table class="grid">
    <tr>
        <td class="bold center" style="width: 15mm;">{{ $t('name') }}:</td>
        <td class="center">{{ $user->name }}</td>
        <td class="bold center" style="width: 32mm;">{{ $t('calendar_week') }}:</td>
        <td class="center" style="width: 30mm;">{{ $week->iso_week }}</td>
        <td class="bold center" style="width: 18mm;">{{ $t('month') }}</td>
        <td class="center" style="width: 15mm;">{{ $week->month }}</td>
        <td class="bold center" style="width: 28mm;">{{ $t('personnel_no') }}:</td>
        <td class="center" style="width: 30mm;">{{ $user->personnel_no }}</td>
    </tr>
</table>

<table class="grid small" style="margin-top: 0;">
    <tr>
        <th style="width: 15mm;">{{ $t('day_column') }}</th>
        <th style="width: 20mm;">{{ $t('date') }}</th>
        <th style="width: 24mm;">{{ $t('cost_center') }}</th>
        <th>{{ $t('customer_site') }}</th>
        <th style="width: 32mm;">{{ $t('work_type_column') }}</th>
        <th style="width: 15mm;">{{ $t('hours') }}</th>
        <th style="width: 15mm;">{{ $t('breaks') }}</th>
        <th style="width: 17mm;">{{ $t('credit_hours') }}</th>
        <th style="width: 17mm;">{{ $t('code') }}</th>
        <th style="width: 17mm;">{{ $t('work_start') }}</th>
        <th style="width: 17mm;">{{ $t('work_end') }}</th>
    </tr>
    @foreach ($entries as $entry)
        <tr>
            <td class="num">{{ $t('days')[$entry->work_date->dayOfWeekIso - 1] }}</td>
            <td class="num">{{ $format->date($entry->work_date) }}</td>
            <td class="num">{{ $entry->project->number }}</td>
            <td><span class="bold">{{ $entry->project->site_name }}</span><br>{{ $entry->project->name }}</td>
            <td class="num">{{ $entry->work_type->documentLabel($format->language->value) }}</td>
            <td class="num">{{ $format->hours($entry->hours) }}</td>
            <td class="num">{{ $entry->break_minutes }}</td>
            <td></td>
            <td></td>
            <td class="num">{{ $entry->startLabel() }}</td>
            <td class="num">{{ $entry->endLabel() }}</td>
        </tr>
    @endforeach
    @for ($i = 0; $i < $emptyRows; $i++)
        <tr>
            <td style="height: 7mm;"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
    @endfor
</table>

<div class="spacer"></div>

<table class="layout">
    <tr>
        <td style="width: 100mm;">
            <table class="grid"><tr><td style="height: 14mm; vertical-align: top;"><span class="bold">{{ $t('remarks') }}:</span></td></tr></table>
        </td>
        <td style="padding-left: 8mm;" class="small">
            <span class="bold">{{ $t('codes_title') }}</span><br>
            {{ $t('codes_line_1') }}<br>
            {{ $t('codes_line_2') }}
        </td>
        <td class="small right" style="width: 75mm; vertical-align: bottom;">
            {{ $t('date_signature') }} ______________________________<br>
            {{ $format->date($lastDate) }}
        </td>
    </tr>
</table>
