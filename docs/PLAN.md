# TM Time Manager — plan i decyzje

Ewidencja czasu → zamknięcie KW → rozliczenie → faktura (KSeF) → pakiet PDF → e-mail,
plus moduł faktur (sprzedaż, zakupy z KSeF, korekty, zaliczki, proformy). Właściciel: ASCOMM Adrian Sajnaga.

## Decyzje

| # | Temat | Decyzja |
|---|---|---|
| 1 | Kilometrówka | Jeden przejazd dziennie na osobę. Trasa: adres bazowy klienta → miejsca zaznaczonych projektów → adres bazowy. Jeden projekt: km = 2 × km w jedną stronę; kilka projektów: km wpisywane ręcznie (np. 27.07.2026: Kiel → Jagel → Eckernförde → Hohn → Kiel = 165 km). |
| 2 | Stawki pracowników | Brak osobnych stawek — stawka godzinowa klienta. |
| 3 | Materiał | Osobno (własne pozycje/rozliczenie), jeśli wystąpi. |
| 4 | Brak połączenia z KSeF | Jak w PM: blokada wystawienia, bez trybu offline. |
| 5 | Numeracja faktur | Wspólna miesięczna seria `{nr}/{miesiąc}/{rok}` (także z PM). Zawsze: ostatni numer z KSeF + 1. Numery z KSeF są jedynym wyznacznikiem — żadnych innych źródeł. |
| 6 | E-mail z fakturą | Nigdy automatycznie: podgląd i ręczne „Wyślij”. |
| — | Testy | Pest (prompt). |
| 7 | Wpis godzin | Start, koniec i przerwa (co 15 min, przerwa 45/30/15/0) — godziny wyliczane, jak w starej aplikacji. |
| 8 | Stundennachweis | Dokument klienta (nagłówek i logo klienta, np. Gärtner). |
| 9 | Strony na dokumentach | Auftraggeber = klient (np. Gärtner), Auftragnehmer = ASCOMM. |
| 10 | Zamykanie | Jednostka = tydzień ISO w obrębie miesiąca (tydzień na przełomie miesięcy = dwie części, osobne Montageaufträge). Część zamyka się po uzupełnieniu opisów; dopiero zamknięte części można fakturować. |
| 11 | Projekty godzinowe | Fakturowane są godziny z zamkniętych części tygodni, niezależnie od statusu projektu. |
| 12 | Projekty ryczałtowe | Godziny tylko informacyjnie; faktura po zakończeniu projektu z bilansem godzin i materiału, także transze/zaliczki (ZAL/ROZ). |
| 13 | Moduł Faktury | Samodzielny, niezależny od projektów: ręczne faktury, lista sprzedaży i zakupów, KSeF. |
| 14 | Asystent AI | Claude Opus 5.5 (Claude API, oficjalne SDK PHP): „AI: popraw” i „AI: na {język klienta}” przy opisach Montageauftrag; klucz API szyfrowany w Administracja → Asystent AI. |
| 15 | PDF faktury | Układ z PM; wersja PL/EN z etykietami jak na fakturach z Excela („FAKTURA VAT / INVOICE”, „Sprzedawca / From”). |
| 16 | VAT dla Gärtnera | `np II` (usługi UE, art. 28b / P_13_9) + adnotacja „odwrotne obciążenie / reverse charge” (P_18=1). Faktura 5/8/2026 z Aplikacji Podatnika KSeF miała `oo` (P_13_10 — krajowe odwrotne obciążenie) — **do potwierdzenia z księgową**; stawkę zmienia się w kartotece kontrahenta. |
| — | Proformy | Własna seria `PF {nr}/{miesiąc}/{rok}`, poza KSeF. |
| — | Kursy walut | Średni kurs NBP (tabela A) z ostatniego dnia roboczego przed datą sprzedaży (lub wystawienia); przycisk „Kurs NBP”, kurs można wpisać ręcznie. |
| — | Baza na serwerze | Nowa baza aplikacji z `.env` (`DB_DATABASE`); stara aplikacja: `iascomm_timemanager` — tylko źródło importu (`LEGACY_DB_DATABASE`). |
| — | Konta | Zakłada administrator. Samodzielna rejestracja tylko w pustej aplikacji (pierwsze konto = administrator). |

## Otwarte pytania (przed fazą, której dotyczą)

- **Stare rejestry faktur:** `ascomm_przychody/koszty/rozlwew` pochodzą z 2015–2016 i wskazują kontrahentów po ID z tabeli, której nie ma w dumpie; `tm_invoice` jest pusta. Do importu potrzebna tabela kontrahentów starego panelu ASCOMM, a dla faktur 2022–03/2026 inne źródło (Excel/PDF). Faktury od 04/2026 przyjdą z KSeF (faza 6).
- **Faza 6 — wzorce z KSeF** (XML z Aplikacji Podatnika, 08–09/2026; pliki nie trafiają do repozytorium — dane osobowe):
  - nabywca z UE: `Podmiot2/DaneIdentyfikacyjne` = `KodUE` + `NrVatUE` (bez prefiksu) + `Nazwa`; `Adres/KodKraju` DE, `AdresL2` „D-24143 Kiel”; `JST`=2, `GV`=2;
  - `KodWaluty` EUR, `P_6`, suma w polu stawki (5/8/2026: `P_13_10` przy `oo`) i `P_15`, bez `P_14_x`; `Adnotacje/P_18`=1;
  - wiersz: `P_8A` „Szt.”, `P_12`, `KursWaluty` w `FaWiersz` (4.3014);
  - nabywca krajowy (3/9/2026 z PM): `NIP`, `P_13_1`/`P_14_1`, `P_1M`, `DaneKontaktowe/Telefon` sprzedawcy; seria numerów wspólna z PM potwierdzona (5/8, 3/9).
- **Faza 7:** test akceptacyjny na pakiecie 4/8/2026; dla KW 35 potrzebne godziny dzienne (lub PDF 2026_9_4).
- **Serwer:** nazwa aplikacji (TM Time Manager / ASCOMM Hours & Invoices).

## Ustalenia z analizy starej aplikacji

- Wpis godzin: data, start/koniec (co 15 min), przerwa 45/30/15/0, godziny wyliczane, projekt, Montage/Demontage, opis.
- Zamknięcie tygodnia: opis per projekt (przyciski „Add” dopisują opisy dzienne), flaga „zafakturowane”.
- Dokumenty: Montageauftrag, Stundenzettel + Zusammenfassung, Stundennachweis (Gärtner, poziomo, Kennzeichen V/S/K/U/UU/SU/F).
- Pakiet 4/8/2026: KW 31–32, 98,75 h × 38 € = 3 752,50 €; 751 km × 0,30 € = 225,30 €; razem 3 977,80 €.
  Etykiety projektów na fakturze łączą się (7 projektów → 5 linii).
- Produkcyjna baza: MySQL 8.4; serwer PHP 8.5 (`/usr/local/bin/php85`), bez `proc_open`.

## Fazy

| Faza | Zakres | Stan |
|---|---|---|
| 0 | Szkielet Laravel 13 + Livewire 4, wdrożenie cPanel (`deploy/`) | ✅ |
| 1 | Analiza, model danych, pytania | ✅ |
| 2 | Role i uprawnienia, PL/EN/DE, dziennik zmian, ustawienia (firma, konta, pojazdy), kontrahenci, projekty | ✅ |
| 3 | Ewidencja czasu, zamykanie KW, Montageauftrag, Stundenzettel, Stundennachweis (mPDF) | ✅ |
| 4 | Import ze starej bazy + raport zgodności | ✅ |
| 5 | Moduł Faktury (samodzielny): VAT/KOR/ZAL/ROZ/proforma, PDF PL i PL/EN, kursy NBP, lista sprzedaży i zakupów | ✅ |
| 6 | KSeF (test): wysyłka, numeracja z KSeF, status, pobieranie sprzedaży i zakupów, walidacja XSD, kod QR | ✅ |
| 7 | Kilometrówka, materiały, rozliczenia godzin → szkic faktury, pakiet PDF, test akceptacyjny 4/8/2026 | ⏳ |
| 8 | E-mail (SMTP z ustawień, szablony, logi) | ⏳ |
| 9 | Projekty ryczałtowe i transze, dashboard | ⏳ |
| 10 | Instrukcja wdrożenia | ⏳ |

## Faza 2 — jak sprawdzić

1. `php artisan migrate:fresh --seed` — konta testowe opisane w `database/seeders/DatabaseSeeder.php`.
2. Administrator widzi w menu Kartoteki (Projekty, Kontrahenci) i Administrację (Użytkownicy, Firma, Konta bankowe, Pojazdy); pracownik — tylko Panel.
3. Język interfejsu: Ustawienia → Profil.
4. `composer ci:check` — Pint, PHPStan (poziom 7), testy.

## Faza 3 — jak sprawdzić

1. `php artisan migrate:fresh --seed` — seeder odtwarza KW 31–32/2026 z pakietu 4/8/2026 (98,75 h).
2. Czas pracy → KW 31: siatka projektów × dni, wpisy start/koniec/przerwa.
3. Tygodnie → KW 32: opisy, materiały, PDF Montageauftrag, Stundennachweis, zamknij/otwórz.
4. Tygodnie → zaznacz zamknięte części + klient → Stundenzettel (98,75 h × 38,00 € = 3.752,50 €).

## Faza 6 — jak sprawdzić

1. Aplikacja Podatnika KSeF **środowiska testowego** (ksef-test.mf.gov.pl) → wygeneruj token (wystawianie i przeglądanie faktur).
2. Administracja → KSeF: środowisko „Testowe”, NIP, token → Zapisz → Test połączenia.
3. Faktura → Wystaw: numer = ostatni numer z KSeF w miesiącu + 1, XML sprawdzany ze schematem FA(3) (`resources/ksef/fa3`), wysyłka szyfrowana, numer KSeF. Odrzucona faktura wraca do szkicu z powodem; niepotwierdzona czeka („Sprawdź status w KSeF”).
4. PDF: numer KSeF i druga strona z kodem QR (KOD I); przycisk XML.
5. Faktury → „Pobierz z KSeF”: sprzedaż (także z PM i Aplikacji Podatnika) i zakupy z okresu; drugie pobranie niczego nie dubluje.
6. Produkcja: dopiero po teście — zmiana środowiska w Administracja → KSeF i token z produkcyjnej Aplikacji Podatnika. Dokumenty z testu znikają z list (zostają w bazie).

## Faza 5 — jak sprawdzić

1. Finanse → Faktury sprzedaży → Nowa faktura → Faktura VAT. Wybór nabywcy ustawia walutę, język faktury, termin płatności, rachunek i stawkę VAT (Gärtner: EUR, PL/EN, `np II`, 14 dni).
2. Zapisz szkic → podgląd: braki przed wystawieniem, PDF (z dopiskiem „Projekt”).
3. „Wystaw” dla VAT/KOR/ZAL/ROZ kończy się komunikatem o braku połączenia z KSeF — numer nadaje tylko KSeF (decyzja 4 i 5). Proforma wystawia się od razu (`PF 1/10/2026`).
4. Korekta: na wystawionej fakturze „Więcej → Wystaw korektę” (pozycje przed korektą + po korekcie, sumy jako różnica).
5. Zaliczkowa: pozycje zamówienia + otrzymana kwota brutto; rozliczeniowa: zamówienie minus wybrane zaliczkowe.
6. Faktury zakupu: ręczny wpis (numer dostawcy, pozycje, data zapłaty). Pobieranie z KSeF — faza 6.

## Faza 4 — import na serwerze

1. cPanel → Manage My Databases: dodaj użytkownika bazy nowej aplikacji do starej bazy `iascomm_timemanager` (wystarczy odczyt).
2. W `/home/iascomm/TimeManager/.env` dopisz `LEGACY_DB_DATABASE=iascomm_timemanager` (oraz `LEGACY_DB_USERNAME` / `LEGACY_DB_PASSWORD`, jeśli to inny użytkownik), potem **Deploy HEAD Commit** (konfiguracja jest w cache).
3. Administracja → Import: najpierw „Sprawdź bez zapisu”, potem „Importuj”. Oczekiwane: 7578 h w 156 tygodniach, 171 zamkniętych części, 170 zafakturowanych.
4. Po imporcie usuń `LEGACY_DB_*` z `.env` i zrób deploy — pozycja Import zniknie z menu.

Import naprawia podwójnie zakodowane teksty (latin2/UTF-8) i „?” zamiast „Ø” przed wymiarem w mm; łączy 7 zdublowanych numerów projektów; nic, co już istnieje w nowej aplikacji, nie jest nadpisywane.
