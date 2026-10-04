{{-- Zestawienie według stawek VAT z wierszem „Razem”. Kolumny Netto | Kwota VAT | Brutto mają (Stawka VAT dowolnie szeroka)
     szerokości kolumn Cena netto | VAT | Wartość netto z tabeli pozycji (invoice-items), więc leżą dokładnie pod nimi. --}}
<table class="data" style="margin-top: 2mm;">
    <tr>
        <td class="blank small" style="width: 40%;"></td>
        <th style="width: 18%;">{!! $th('vat_rate') !!}</th>
        <th style="width: 14%;">{!! $th('net') !!}</th>
        <th style="width: 13%;">{!! $th('vat_amount') !!}</th>
        <th style="width: 15%;">{!! $th('gross') !!}</th>
    </tr>
    @foreach ($vatSummary->rows() as $index => $row)
        <tr>
            <td class="blank small">{{ $index === 0 ? ($caption ?? '') : '' }}</td>
            <td class="ctr">{{ $row['code']->shortLabel() }}</td>
            <td class="num">{{ $money($row['net']) }}</td>
            <td class="num">{{ $row['code']->percent() === null ? '—' : $money($row['vat']) }}</td>
            <td class="num">{{ $money($row['gross']) }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td class="blank"></td>
        <td class="ctr">{{ $t('total') }}</td>
        <td class="num">{{ $money($vatSummary->net()) }}</td>
        <td class="num">{{ $money($vatSummary->vat()) }}</td>
        <td class="num">{{ $money($vatSummary->gross()) }}</td>
    </tr>
</table>
