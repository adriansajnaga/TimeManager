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
| — | Baza na serwerze | Nowa baza `ascomm_tm`; stara `iascomm_tm` tylko jako źródło importu. |
| — | Konta | Zakłada administrator. Samodzielna rejestracja tylko w pustej aplikacji (pierwsze konto = administrator). |

## Otwarte pytania (przed fazą, której dotyczą)

- **Faza 4:** dump starej bazy **z danymi**; import `ascomm_przychody/koszty` (faktury sprzed KSeF) — tak/nie.
- **Faza 5:** test akceptacyjny na pakiecie 4/8/2026; dla KW 35 potrzebne godziny dzienne (lub PDF 2026_9_4).
- **Faza 6:** układ faktury PL/EN (PM + etykiety EN czy układ z Excela FROM/FOR).
- **Faza 7:** stawka dla Gärtnera w FA(3) — najpewniej `np II` (P_13_9) z adnotacją „odwrotne obciążenie”; potwierdzić z księgową, zweryfikować z XSD.
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
| 4 | Import ze starej bazy + raport zgodności | ⏳ |
| 5 | Kilometrówka, materiały, rozliczenia, pakiet PDF, test akceptacyjny 4/8/2026 | ⏳ |
| 6 | Faktury lokalnie (VAT/KOR/ZAL/ROZ/proforma), PDF PL i PL/EN, kursy NBP | ⏳ |
| 7 | KSeF (test): wysyłka, numeracja z KSeF, status/UPO, pobieranie sprzedaży i zakupów, walidacja XSD | ⏳ |
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
