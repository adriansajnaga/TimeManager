@include('pdf.partials.company-header')

<div class="spacer"></div>
<h1>{{ $t('stundenzettel') }}</h1>
<div class="spacer"></div>

@include('pdf.partials.parties')

@foreach ($sections as $section)
    <div class="spacer"></div>
    <table class="grid small">
        @include('pdf.partials.week-header', ['days' => $section['week']->weekDays()])
        @foreach ($section['rows'] as $row)
            <tr>
                <td class="num">{{ $section['week']->iso_week }}</td>
                <td class="center">{{ $row->user->name }}</td>
                @foreach ($section['week']->weekDays() as $index => $day)
                    <td class="num">{{ $format->hours($row->day($index)) }}</td>
                @endforeach
                <td class="num">{{ $format->hours($row->total) }}</td>
            </tr>
        @endforeach
    </table>
@endforeach

<div class="spacer"></div>
<div class="spacer"></div>

<p class="section-title">{{ $t('summary') }}:</p>
<table class="grid" style="width: 120mm;">
    <tr class="strong">
        <td class="center">{{ $t('summary_name') }}</td>
        <td class="center">{{ $t('summary_hours') }}</td>
        <td class="center">{{ $t('summary_rate') }}</td>
        <td class="center">{{ $t('summary_total') }}</td>
    </tr>
    @foreach ($summary as $line)
        <tr class="strong">
            <td class="center">{{ $line['user']->name }}</td>
            <td class="center">{{ $format->hours($line['hours']) }}</td>
            <td class="center">{{ $format->money($line['rate'], $currency) }}</td>
            <td class="center">{{ $format->money($line['amount'], $currency) }}</td>
        </tr>
    @endforeach
</table>
