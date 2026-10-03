{{-- Pozycje faktury: Lp., nazwa, ilość, j.m., cena netto, VAT, wartość netto. --}}
<table class="data">
    <tr>
        <th style="width: 7%;">{!! $th('position') !!}</th>
        <th>{!! $th('description') !!}</th>
        <th style="width: 9%;">{!! $th('quantity') !!}</th>
        <th style="width: 8%;">{!! $th('unit') !!}</th>
        <th style="width: 15%;">{!! $th('unit_price') !!}</th>
        <th style="width: 8%;">{!! $th('vat') !!}</th>
        <th style="width: 16%;">{!! $th('net_value') !!}</th>
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
