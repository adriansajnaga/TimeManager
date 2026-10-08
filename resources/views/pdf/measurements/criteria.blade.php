{{-- Warunki prób i kryteria oceny — tylko dla badań, które są w protokole. --}}
@php
    $intro = 'Ocenę stanu bezpieczeństwa przeciwporażeniowego badanej instalacji elektrycznej przeprowadzono w oparciu o postanowienia przepisów aktów prawnych i dokumentów normalizacyjnych wymienionych na stronie „Akty prawne i dokumenty normalizacyjne”.';
@endphp

@if ($hasRcd)
    <pagebreak />
    <div class="criteria">
        <h2 class="crit-title">Warunki przeprowadzenia prób i pomiarów oraz kryteria oceny</h2>
        <h3 class="crit-topic">badanie urządzeń różnicowoprądowych</h3>
        <p>{{ $intro }}</p>
        <p>Ocenę sprawności urządzeń ochronnych różnicowoprądowych (wyłączników różnicowoprądowych) przeprowadzono zgodnie z wymogami ujętymi w normie PN-HD 60364-6:2016-07.</p>
        <table class="grid" style="width: 70%; margin: 2mm auto;">
            <tr><th>Typ wyłącznika</th><th>Warunek prądu zadziałania</th></tr>
            <tr><td>AC</td><td>0,5·I∆n ≤ Id ≤ I∆n</td></tr>
            <tr><td>A, F</td><td>0,35·I∆n ≤ Id ≤ 1,4·I∆n</td></tr>
            <tr><td>B</td><td>0,5·I∆n ≤ Id ≤ 2·I∆n</td></tr>
        </table>
        <p>Gdzie: I∆n – wartość prądu znamionowego różnicowego zadziałania [mA], Id – wartość prądu, przy której zadziała wyłącznik różnicowoprądowy [mA].</p>
        <p>Dodatkowo czas zadziałania sprawdzono zgodnie z następującymi warunkami (typ bezzwłoczny): 1×I∆n – ta &lt; 300 ms, 2×I∆n – ta &lt; 150 ms, 5×I∆n – ta &lt; 40 ms; typ selektywny (S) przy 1×I∆n: 130 ms ≤ ta ≤ 500 ms.</p>
        <p>Sprawdzono działanie członu kontrolnego wyłącznika różnicowoprądowego (przycisku „TEST”) – po jego naciśnięciu wyłącznik powinien natychmiast zadziałać.</p>
    </div>
@endif

@if ($hasPoints || $hasSupply)
    <pagebreak />
    <div class="criteria">
        <h2 class="crit-title">Warunki przeprowadzenia prób i pomiarów oraz kryteria oceny</h2>
        <h3 class="crit-topic">badanie impedancji pętli zwarcia</h3>
        <p>{{ $intro }}</p>
        <p>Próby i pomiary parametrów technicznych badanej instalacji elektrycznej zostały wykonane w warunkach zbliżonych do warunków jej normalnej pracy, zgodnie z postanowieniami normy PN-HD 60364-4-41:2017-09.</p>
        <p><b>1 – dla układu sieci TN</b>, zgodnie z postanowieniami punktu 411.4.4 normy PN-HD 60364-4-41:</p>
        <p class="formula">Zs · Ia ≤ Uo</p>
        <p>Dzieląc obustronnie powyższą nierówność przez impedancję Zs warunek otrzymuje postać Ia ≤ Ik, a przez prąd Ia – postać Zs ≤ Za.</p>
        <p><b>2 – dla układu sieci TT</b>, zgodnie z postanowieniami punktu 411.5.4 normy PN-HD 60364-4-41: tam, gdzie występuje wyłącznik RCD: Ra · I∆n ≤ 50 V; tam, gdzie jako ochrona występuje wyłącznik nadprądowy: Zs · Ia ≤ Uo.</p>
        <p class="small">Gdzie: Ra – suma rezystancji uziemienia części przewodzących dostępnych, Zs – zmierzona impedancja pętli zwarcia [Ω], Za – dopuszczalna impedancja pętli zwarcia [Ω], Ia – prąd powodujący samoczynne zadziałanie urządzenia wyłączającego w wymaganym czasie [A] (wyłączniki B: 5·In, C: 10·In, D: 20·In; wkładki gG wg charakterystyki czasowo-prądowej), Ik – prąd zwarcia jednofazowego [A], Uo – napięcie znamionowe względem ziemi [V].</p>
    </div>
@endif

@if ($protocol->earthings->isNotEmpty())
    <pagebreak />
    <div class="criteria">
        <h2 class="crit-title">Warunki przeprowadzenia prób i pomiarów oraz kryteria oceny</h2>
        <h3 class="crit-topic">badanie rezystancji uziemienia</h3>
        <p>Pomiar rezystancji uziemienia przeprowadzono zgodnie z zaleceniami normy PN-HD 60364-6:2016-07, załącznik C, przyrządem zgodnym co do metody opisanej w przywołanej normie, w świetle wymagań stawianych przez PN-HD 60364-5-54:2011.</p>
        <p>Wynik spełnia wymagania, gdy rezystancja zmierzona po uwzględnieniu współczynnika korekcyjnego nie przekracza wartości wymaganej:</p>
        <p class="formula">RE · Kp ≤ Ra</p>
    </div>
@endif

@if ($protocol->continuities->isNotEmpty())
    <pagebreak />
    <div class="criteria">
        <h2 class="crit-title">Warunki przeprowadzenia prób i pomiarów oraz kryteria oceny</h2>
        <h3 class="crit-topic">badanie ciągłości przewodów ochronnych</h3>
        <p>{{ $intro }}</p>
        <p>Próby i pomiary zostały wykonane w warunkach zbliżonych do warunków normalnej pracy instalacji, zgodnie z postanowieniami rozdziału 6.4.3.2 normy PN-HD 60364-6:2016-07. Wykonano próbę ciągłości elektrycznej: a) przewodów ochronnych, w tym przewodów ochronnych w połączeniach wyrównawczych, b) części czynnych dostępnych, c) przewodów czynnych w obwodach pierścieniowych.</p>
        <p>Próby przeprowadzono miernikiem wykorzystującym prąd stały lub przemienny o napięciu od 4 V do 24 V, prądem co najmniej 0,2 A. Błąd pomiarowy nie może przekraczać 30% w zakresie od 0,2 Ω do 2 Ω.</p>
    </div>
@endif

@if ($hasInsulation || $protocol->cableTests->isNotEmpty())
    <pagebreak />
    <div class="criteria">
        <h2 class="crit-title">Warunki przeprowadzenia prób i pomiarów oraz kryteria oceny</h2>
        <h3 class="crit-topic">badanie rezystancji izolacji</h3>
        <p>{{ $intro }}</p>
        <p>Próby i pomiary zostały wykonane w warunkach zbliżonych do warunków normalnej pracy instalacji, zgodnie z postanowieniami rozdziału 6.4.3.3 normy PN-HD 60364-6:2016-07.</p>
        <p class="formula">Rs ≥ Ra</p>
        <p>Gdzie: Rs – zmierzona wartość rezystancji izolacji, Ra – wymagana wartość rezystancji izolacji, zależna od napięcia znamionowego obwodu:</p>
        <table class="grid" style="width: 80%; margin: 2mm auto;">
            <tr><th>Napięcie znamionowe obwodu</th><th>Napięcie probiercze prądu stałego</th><th>Wymagana rezystancja izolacji Ra</th></tr>
            <tr><td>Obwody SELV i PELV</td><td>250 V</td><td>≥ 0,5 MΩ</td></tr>
            <tr><td>≤ 500 V, z wyjątkiem SELV i PELV</td><td>500 V</td><td>≥ 1,0 MΩ</td></tr>
            <tr><td>&gt; 500 V</td><td>1000 V</td><td>≥ 1,0 MΩ</td></tr>
        </table>
    </div>
@endif
