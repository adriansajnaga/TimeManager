{{-- Nagłówek tabeli godzin: KW | Name | MO … SO (z datami) | Gesamt --}}
<tr>
    <th style="width: 12mm;">{{ $t('week_short') }}</th>
    <th>{{ $t('name') }}</th>
    @foreach ($days as $index => $day)
        <th style="width: 15mm;"><span class="small">{{ $t('days')[$index] }}</span><br><span class="tiny">{{ $format->date($day) }}</span></th>
    @endforeach
    <th style="width: 15mm;">{{ $t('total') }}</th>
</tr>
