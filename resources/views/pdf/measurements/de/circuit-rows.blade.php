{{-- Wiersze obwodów; puste wiersze z „x” w kolumnie Leiter, jak w formularzu. --}}
@foreach ($rows as $row)
    @if ($row === null)
        <tr>
            @for ($i = 0; $i < 19; $i++)
                <td>{{ $i === 3 ? 'x' : '' }}</td>
            @endfor
        </tr>
    @elseif ($row['type'] === 'board')
        <tr><td colspan="19" class="board">Verteiler Nr.: {{ $row['name'] }}</td></tr>
    @else
        <tr>
            <td>{{ $row['number'] }}</td>
            <td class="left">{{ $row['place'] }}</td>
            <td>{{ $row['cableType'] }}</td>
            <td>{{ $row['conductors'] !== '' ? $row['conductors'] : 'x' }}</td>
            <td>{{ $row['protection'] }}</td>
            <td>{{ $row['in'] }}</td>
            <td>{{ $row['zs'] }}</td>
            <td>{{ $row['ik'] }}</td>
            <td>{{ $row['riso'] }}</td>
            <td></td>
            <td>{{ $row['voltage'] }}</td>
            <td>{{ $row['rcdIn'] }}</td>
            <td>{{ $row['rcdIdn'] }}</td>
            <td>{{ $row['rcdImess'] }}</td>
            <td>{{ $row['rcdTime'] }}</td>
            <td>{{ $row['rcdU'] }}</td>
            <td></td>
            <td style="font-weight: bold;">{{ $row['ok'] === false ? 'X' : '' }}</td>
            <td style="font-weight: bold;">{{ $row['ok'] === true ? 'X' : '' }}</td>
        </tr>
    @endif
@endforeach
