<h2>POZOSTAŁE ZAŁĄCZNIKI</h2>
@if ($protocol->performers->contains(fn ($performer) => $performer->attachments->isNotEmpty()))
    <p>- Kserokopie uprawnień osób przeprowadzających badania</p>
@endif
@if ($protocol->instrument?->attachments->isNotEmpty())
    <p>- Świadectwo wzorcowania miernika</p>
@endif
