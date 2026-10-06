{{-- Elewacja rozdzielnicy jako załącznik protokołu. --}}
<h2>{{ $title }}</h2>
<h3>Rozmieszczenie zabezpieczeń w rozdzielnicy {{ $board->name }}</h3>
@include('pdf.measurements.partials.rails', ['rows' => $rows, 'rail' => $rail, 'module' => min(12, 170 / max(1, $rail)), 'descHeight' => 32])
