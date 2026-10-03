{{-- Zestawienie według stawek VAT z wierszem „Razem”. --}}
<table class="layout" style="margin-top: 2mm;">
    <tr>
        <td class="small" style="width: 38%; vertical-align: middle; padding-right: 3mm;">{{ $caption ?? '' }}</td>
        <td style="width: 62%;">
            <table class="data">
                <tr>
                    <th>{!! $th('vat_rate') !!}</th>
                    <th>{!! $th('net') !!}</th>
                    <th>{!! $th('vat_amount') !!}</th>
                    <th>{!! $th('gross') !!}</th>
                </tr>
                @foreach ($vatSummary->rows() as $row)
                    <tr>
                        <td class="ctr">{{ $row['code']->shortLabel() }}</td>
                        <td class="num">{{ $money($row['net']) }}</td>
                        <td class="num">{{ $row['code']->percent() === null ? '—' : $money($row['vat']) }}</td>
                        <td class="num">{{ $money($row['gross']) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td class="ctr">{{ $t('total') }}</td>
                    <td class="num">{{ $money($vatSummary->net()) }}</td>
                    <td class="num">{{ $money($vatSummary->vat()) }}</td>
                    <td class="num">{{ $money($vatSummary->gross()) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
