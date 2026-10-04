{{-- Pozycje faktury: Lp., nazwa, ilość, j.m., cena netto, VAT, wartość netto.
     Szerokości kolumn zgrane z zestawieniem VAT (invoice-vat): Netto | Kwota VAT | Brutto pod Cena netto | VAT | Wartość netto. --}}
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
</table>
