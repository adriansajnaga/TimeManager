@include('pdf.partials.company-header')

<div class="spacer"></div>
<h1>{{ $t('montageauftrag') }}</h1>
<div class="spacer"></div>

@include('pdf.partials.parties')

<div class="spacer"></div>

<p class="section-title">{{ $t('project') }}:</p>
<p><span class="bold">{{ $project->number }}</span> - {{ $project->name }}</p>
<p>{{ $project->site_name }}</p>
<p>{{ $project->site_street }}</p>
<p>{{ trim($project->site_zip.' '.$project->site_city) }}</p>

<div class="spacer"></div>

<table class="layout">
    <tr><td class="section-title">{{ $t('performed_work') }}:</td></tr>
    <tr><td class="text-box">{!! nl2br(e($report->performed_work)) !!}</td></tr>
</table>

<table class="layout">
    <tr><td class="section-title">{{ $t('remaining_work') }}:</td></tr>
    <tr><td class="text-box" style="height: 22mm;">{!! nl2br(e($report->remaining_work)) !!}</td></tr>
</table>

<p class="section-title">{{ $t('material') }}:</p>
<table class="layout">
    <tr>
        @foreach ($materialColumns as $columnIndex => $column)
            @if ($columnIndex === 1)
                <td style="width: 6mm;"></td>
            @endif
            <td style="width: 87mm;">
                <table class="grid small">
                    <tr>
                        <th style="width: 14mm;">{{ $t('line_no') }}</th>
                        <th>{{ $t('designation') }}</th>
                        <th style="width: 18mm;">{{ $t('quantity_unit') }}</th>
                    </tr>
                    @foreach ($column as $row)
                        <tr>
                            <td class="num">{{ $row['material'] ? $row['no'] : '' }}</td>
                            <td>{{ $row['material']?->name }}&nbsp;</td>
                            <td class="num">{{ $row['material'] ? trim($row['material']->quantityLabel().' '.$row['material']->unit) : '' }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        @endforeach
    </tr>
</table>

<div class="spacer"></div>

<p class="section-title">{{ $t('hours') }}:</p>
<table class="grid small">
    @include('pdf.partials.week-header')
    @foreach ($hours as $row)
        <tr>
            <td class="num">{{ $week->iso_week }}</td>
            <td>{{ $row->user->name }}</td>
            @foreach ($days as $index => $day)
                <td class="num">{{ $format->hours($row->day($index)) }}</td>
            @endforeach
            <td class="num">{{ $format->hours($row->total) }}</td>
        </tr>
    @endforeach
    @for ($i = 0; $i < $emptyHourRows; $i++)
        <tr>
            <td>&nbsp;</td><td></td>
            @foreach ($days as $day)<td></td>@endforeach
            <td></td>
        </tr>
    @endfor
</table>

<div class="spacer"></div>

<p class="section-title">{{ $t('date') }}:</p>
<p>{{ $format->date($reportDate) }}</p>
