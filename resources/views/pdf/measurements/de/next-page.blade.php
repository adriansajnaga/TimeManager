{{-- Kolejna strona formularza: sama tabela obwodów (33 wiersze) i podpisy. --}}
@include('pdf.measurements.de.header')
<table class="circuits tall">
    @include('pdf.measurements.de.circuit-head')
    @include('pdf.measurements.de.circuit-rows')
</table>
@include('pdf.measurements.de.signatures', ['gap' => 10])
