{{-- Kilometrówka wystawiana przez ASCOMM (nagłówek firmy); klient tylko jako zleceniodawca. --}}
@include('pdf.partials.company-header')

<div class="spacer"></div>

<table class="layout">
    <tr>
        <td>
            <h1>{{ $t('mileage.title') }}</h1>
            <span class="small">{{ $t('client') }}: {{ $client->name }}</span>
        </td>
        <td class="right" style="width: 60mm;">
            <span class="bold">{{ $t('week_short') }} {{ $period }}</span><br>
            <span class="small">{{ $t('name') }}: {{ $user->name }}</span><br>
            @if ($vehicle)
                <span class="small">{{ $t('mileage.vehicle') }}: {{ $vehicle->displayName() }}</span>
            @endif
        </td>
    </tr>
</table>

<div class="spacer"></div>

<table class="grid small">
    <tr class="strong">
        <td>{{ $t('mileage.cost_of_route') }}</td>
        <td class="center" style="width: 22mm;">{{ $t('mileage.km') }}</td>
        <td class="center" style="width: 28mm;">{{ $t('mileage.rate') }}</td>
        <td class="center" style="width: 32mm;">{{ $t('mileage.allowance') }} [{{ $currency }}]</td>
    </tr>
    @foreach ($weeks as $week)
        <tr>
            <td>{{ $t('mileage.sum_for_week') }} {{ $week['iso_week'] }}</td>
            <td class="num">{{ $km($week['km']) }}</td>
            <td class="num">{{ $format->number($rate, 2) }}</td>
            <td class="num">{{ $format->number($week['amount']) }}</td>
        </tr>
    @endforeach
    <tr class="strong">
        <td class="right">{{ $t('mileage.sum') }}:</td>
        <td class="num">{{ $km($totalKm) }}</td>
        <td></td>
        <td class="num">{{ $format->number($totalAmount) }}</td>
    </tr>
</table>

@foreach ($weeks as $week)
    <div class="spacer"></div>
    <table class="grid small">
        <tr class="strong">
            <td style="width: 32mm;">{{ $t('mileage.week_number') }}: {{ $week['iso_week'] }}</td>
            <td>{{ $t('mileage.route_hint') }}</td>
            <td class="center" style="width: 18mm;">{{ $t('mileage.km') }}</td>
        </tr>
        @foreach ($week['days'] as $day)
            <tr>
                <td>{{ $t('date') }}: {{ $day['date']->format('Y.m.d') }}</td>
                <td>{{ $day['trip']?->route }}</td>
                <td class="num">{{ $day['trip'] ? $day['trip']->kmLabel() : '' }}</td>
            </tr>
        @endforeach
    </table>
@endforeach
