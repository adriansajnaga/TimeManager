{{-- Pozycje faktury: Lp., nazwa, ilość, j.m., cena netto, VAT, wartość netto.
     Zestawienie VAT ($summary) jest dolnymi wierszami tej samej tabeli, więc Netto | Kwota VAT | Brutto
     leżą dokładnie pod Cena netto | VAT | Wartość netto. --}}
<table class="data items">
    <tr>
        <th style="width: 6%;">{!! $th('position') !!}</th>
        <th style="width: 35%;">{!! $th('description') !!}</th>
        <th style="width: 8%;">{!! $th('quantity') !!}</th>
        <th style="width: 9%;">{!! $th('unit') !!}</th>
        <th style="width: 14%;">{!! $th('unit_price') !!}</th>
        <th style="width: 13%;">{!! $th('vat') !!}</th>
        <th style="width: 15%;">{!! $th('net_value') !!}</th>
    </tr>
    @foreach ($items as $item)
        <tr>
            <td class="ctr">{{ $item->position }}</td>
            <td>{!! nl2br(e($item->name)) !!}</td>
            <td class="ctr">{{ $quantity($item->quantity) }}</td>
            <td class="ctr">{{ $item->unit }}</td>
            <td class="num">{{ $money($item->unit_price) }}</td>
            <td class="ctr">{{ $item->vat_code->shortLabel() }}</td>
            <td class="num">{{ $money($item->net) }}</td>
        </tr>
    @endforeach

    @isset($summary)
        <tr class="sum">
            <td class="blank small" colspan="3" rowspan="{{ count($summary->rows()) + 2 }}" style="vertical-align: top; padding-top: 2mm;">{{ $caption ?? '' }}</td>
            <th>{!! $th('vat_rate') !!}</th>
            <th>{!! $th('net') !!}</th>
            <th>{!! $th('vat_amount') !!}</th>
            <th>{!! $th('gross') !!}</th>
        </tr>
        @foreach ($summary->rows() as $row)
            <tr class="sum">
                <td class="ctr">{{ $row['code']->shortLabel() }}</td>
                <td class="num">{{ $money($row['net']) }}</td>
                <td class="num">{{ $row['code']->percent() === null ? '—' : $money($row['vat']) }}</td>
                <td class="num">{{ $money($row['gross']) }}</td>
            </tr>
        @endforeach
        <tr class="sum total">
            <td class="ctr">{{ $t('total') }}</td>
            <td class="num">{{ $money($summary->net()) }}</td>
            <td class="num">{{ $money($summary->vat()) }}</td>
            <td class="num">{{ $money($summary->gross()) }}</td>
        </tr>
    @endisset
</table>
