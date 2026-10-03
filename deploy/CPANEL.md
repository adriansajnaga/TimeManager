# Wdrożenie na cPanel (Git Version Control)

Tak samo jak Energieableseportal (`/em`), tylko w `/tm`:

```
/home/iascomm/TimeManager/      repozytorium: kod, vendor/, .env, storage/   (niedostępne z internetu)
/home/iascomm/public_html/tm/   kopia public/ + deploy/public_html/index.php
```

„Deploy HEAD Commit” uruchamia `.cpanel.yml` → `deploy/deploy.sh`:
szuka PHP CLI >= 8.4.1, pobiera własny `composer.phar`, `composer install --no-dev --no-scripts`
(hosting nie ma `proc_open`), czyści konfigurację z cache, kopiuje `public/` do `public_html/tm`,
pokazuje bazę z `.env` (`db:show`), uruchamia migracje i buduje cache.
Log: `~/TimeManager/storage/logs/deploy.log` — po każdym wdrożeniu sprawdź sekcje „Baza danych” i „Migracje”.

Serwer nie potrzebuje Node.js: zbudowany frontend (`public/build`) jest w repozytorium.
Biblioteki są w czystym PHP (mPDF, phpseclib, Anthropic SDK); PHP potrzebuje rozszerzeń:
`ctype curl dom fileinfo filter gd hash mbstring openssl pdo_mysql session tokenizer xml`
(`dom`/`libxml` — walidacja XML faktur ze schematem FA(3), `openssl` — szyfrowanie wysyłki do KSeF).

## Każde wdrożenie

Lokalnie: commit w GitHub Desktop → **Push origin** (przy zmianach frontendu najpierw `npm run build`).

W cPanel: **Git Version Control → Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit**,
potem `storage/logs/deploy.log`.

## Bazy danych

| Baza | Do czego |
|---|---|
| `iascomm_tm` | baza aplikacji (`DB_DATABASE`, użytkownik `iascomm_tm`) |
| `iascomm_timemanager` | stara aplikacja TM — tylko źródło importu (`LEGACY_DB_DATABASE`), odczyt |

## `.env` na serwerze

Wzór: lokalny `.env.production` (nie jest w repozytorium). Najważniejsze wpisy:

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:…            # nie zmieniaj po uruchomieniu — szyfruje token KSeF, hasło SMTP i klucz AI
APP_URL=https://<domena>/tm

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=iascomm_tm
DB_USERNAME=iascomm_tm
DB_PASSWORD=…

SESSION_PATH=/tm
```

Import ze starej aplikacji (jednorazowo): `LEGACY_DB_DATABASE=iascomm_timemanager`
(`LEGACY_DB_USERNAME`/`LEGACY_DB_PASSWORD` tylko, gdy to inny użytkownik niż `DB_USERNAME`).
Po imporcie usuń ten wpis i zrób deploy — pozycja Import zniknie z menu.

Sekretów (token KSeF, hasło SMTP, klucz API Claude) **nie wpisuje się do `.env` ani do repozytorium** —
podaje się je w aplikacji (Administracja), a baza przechowuje je zaszyfrowane kluczem `APP_KEY`.

**`APP_DEBUG=false` na produkcji zawsze** — z `true` strona błędu pokazuje dane konfiguracji.
Po zmianie `.env` zrób Deploy HEAD Commit (konfiguracja jest w cache).

## Pierwsze wdrożenie (jednorazowo)

1. **Manage My Databases**: baza `iascomm_tm`, użytkownik `iascomm_tm` z ALL PRIVILEGES.
2. **Git Version Control → Create**: Clone URL repozytorium z GitHuba, Repository Path: `/home/iascomm/TimeManager`.
3. **Menedżer plików** (Settings → Show Hidden Files): wgraj `.env.production` do `/home/iascomm/TimeManager` jako `.env`,
   uzupełnij `APP_URL`, `DB_PASSWORD`.
4. **Pull or Deploy → Deploy HEAD Commit**, sprawdź `storage/logs/deploy.log`.
5. Otwórz `https://<domena>/tm` → **Zarejestruj** pierwsze konto (zostaje administratorem; potem rejestracja się wyłącza).

## Konfiguracja w aplikacji (Administracja)

1. **Firma**: dane sprzedawcy, logo, miejsce wystawienia, domyślny termin płatności i stawka VAT.
2. **Konta bankowe** i **Pojazdy** (pojazd na kilometrówce).
3. **Import** (gdy `LEGACY_DB_DATABASE` w `.env`): użytkownik `iascomm_tm` musi mieć dostęp SELECT do `iascomm_timemanager`
   (Manage My Databases → Add User To Database). „Sprawdź bez zapisu”, potem „Importuj”.
4. **KSeF**: najpierw środowisko **Testowe** — token z Aplikacji Podatnika środowiska testowego, Test połączenia,
   próbna faktura. Produkcja: zmiana środowiska i token z produkcyjnej Aplikacji Podatnika
   (uprawnienia: wystawianie i przeglądanie faktur). Faktury z testu znikają z list po przełączeniu.
   Potem Faktury → „Pobierz z KSeF” od 01.04.2026 (sprzedaż, także z PM, i zakupy).
5. **E-mail**: serwer SMTP (np. `mail.<domena>`, port 465, SSL), skrzynka i hasło, nadawca → wiadomość testowa.
6. **Asystent AI** (opcjonalnie): klucz z console.anthropic.com → Test połączenia.
7. **Kontrahenci**: stawka godzinowa i za km, adres bazowy kilometrówki, waluta, stawka VAT
   (Gärtner: `np II` — patrz `docs/PLAN.md`, decyzja 16), szablony opisu faktury i e-maila, kolejność dokumentów pakietu.
8. **Użytkownicy**: konta pracowników (rola Pracownik — tylko własny czas i opisy).

## Codzienna praca

Czas pracy → Tygodnie (opisy, materiał, kilometrówka, zamknięcie) → Finanse → Rozliczenia (szkic faktury)
→ faktura: Wystaw (numer i wysyłka do KSeF) → Pakiet (PDF) → Wyślij e-mailem (zawsze ręcznie).

## Kopie zapasowe

- cPanel → **Backup** → „Download a MySQL Database Backup” dla `iascomm_tm` (regularnie, np. co tydzień i przed większym wdrożeniem).
- Pliki spoza repozytorium: `~/TimeManager/.env` i `~/TimeManager/storage/app` (logo firmy i klientów) — Backup → Home Directory albo Menedżer plików.
- `APP_KEY` przechowuj osobno w bezpiecznym miejscu: bez niego zaszyfrowanych tokenów i haseł nie da się odczytać.

## Cron (opcjonalnie)

Aplikacja wysyła e-maile od razu, bez kolejki. Gdyby kiedyś były zadania w kolejce:
**Cron Jobs**, co minutę: `/bin/bash /home/iascomm/TimeManager/deploy/artisan.sh queue:work --stop-when-empty --tries=3`

## Uwagi

- Nie używaj `php artisan optimize` ani `route:cache` na serwerze: w podkatalogu `/tm/` cache tras psuje stronę startową (405).
- Linki w widokach zawsze przez `route()` / `asset()`, nigdy `/coś`: w podkatalogu prowadziłyby poza `/tm`.
- `SESSION_PATH=/tm` oddziela ciasteczka TM od EM na tej samej domenie.
- Nie edytuj plików w `~/TimeManager` przez Menedżer plików (poza `.env`): cPanel wdraża tylko czyste repozytorium.
- Schemat FA(3) do walidacji XML jest w `resources/ksef/fa3` (pliki z crd.gov.pl). Przy nowej wersji schematy MF podmień pliki i namespace w `Fa3InvoiceBuilder`.
