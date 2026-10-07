{{-- Nagłówek sekcji jak w protokole: PROTOKÓŁ, dalszy ciąg zdania, opcjonalnie rodzaj pomiaru, „Wyniki przeprowadzonych prób:”. --}}
<p style="text-align: center; font-size: 17pt; font-weight: bold; margin: 2mm 0 0;">PROTOKÓŁ</p>
<p style="text-align: center; font-size: 9pt; margin: 1mm 0 0;">{{ $subtitle }}</p>
@isset($measurement)
    <p style="text-align: center; font-size: 9pt; font-weight: bold; margin: 1mm 0 0;">{!! $measurement !!}</p>
@endisset
@isset($facts)
    <table style="margin: 5mm 0 0 25mm; font-size: 9pt;">
        @foreach ($facts as $label => $value)
            <tr><td style="padding: 0.4mm 2mm;">-</td><td style="padding: 0.4mm 2mm; width: 60mm;">{{ $label }}:</td><td style="padding: 0.4mm 2mm;">{!! $value !!}</td></tr>
        @endforeach
    </table>
@endisset
<p style="text-align: center; font-size: 9.5pt; margin: 6mm 0 2mm;">Wyniki przeprowadzonych prób:</p>
