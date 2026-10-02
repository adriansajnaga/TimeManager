# Wdrożenie na cPanel (Git Version Control)

Tak samo jak Energieableseportal (`/em`), tylko w `/tm`:

```
/home/iascomm/TimeManager/   repozytorium: kod, vendor/, .env, storage/   (niedostępne z internetu)
/home/iascomm/public_html/tm/   kopia public/ + deploy/public_html/index.php
```

„Deploy HEAD Commit” uruchamia `.cpanel.yml` → `deploy/deploy.sh`:
szuka PHP CLI >= 8.4.1, pobiera własny `composer.phar`, `composer install --no-dev --no-scripts`
(hosting nie ma `proc_open`), kopiuje `public/` do `public_html/tm`, migracje, cache.
Log: `~/TimeManager/storage/logs/deploy.log`.

Serwer nie potrzebuje Node.js: zbudowany frontend (`public/build`) jest w repozytorium.

## Każde wdrożenie

Lokalnie:

```
npm run build
git add -A
git commit -m "opis zmian"
git push
```

W cPanel: **Git Version Control → Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit**.

## Pierwsze wdrożenie (jednorazowo)

1. **Manage My Databases**: baza `iascomm_tm`, użytkownik `iascomm_tm` z ALL PRIVILEGES.
2. **Git Version Control → Create**: Clone URL repozytorium z GitHuba (dostęp jak dla Energieableseportal),
   Repository Path: `/home/iascomm/TimeManager`.
3. **Menedżer plików** (Settings → Show Hidden Files): wgraj lokalny plik `.env.production` do
   `/home/iascomm/TimeManager` jako `.env` i uzupełnij `APP_URL`, `DB_PASSWORD`, `MAIL_*`.
4. **Pull or Deploy → Deploy HEAD Commit**, potem sprawdź `storage/logs/deploy.log`.
5. **Cron Jobs**, co minutę (wysyłka maili z kolejki; hosting bez `proc_open`, więc bez `schedule:run`):
   `/bin/bash /home/iascomm/TimeManager/deploy/artisan.sh queue:work --stop-when-empty --tries=3`

## Uwagi

- Nie używaj `php artisan optimize` ani `route:cache` na serwerze: w podkatalogu `/tm/` cache tras psuje stronę startową (405).
- Linki w widokach zawsze przez `route()` / `asset()`, nigdy `/coś`: w podkatalogu prowadziłyby poza `/tm`.
- `SESSION_PATH=/tm` oddziela ciasteczka TM od EM na tej samej domenie.
- Nie edytuj plików w `~/TimeManager` przez Menedżer plików (poza `.env`): cPanel wdraża tylko czyste repozytorium.
