# Wdrożenie na cPanel (Git Version Control)

Układ na serwerze:

```
~/timemanager/   repozytorium: kod, vendor/, .env, storage/   (niedostępne z internetu)
~/public_html/   zawartość public/ + index.php wskazujący na ~/timemanager
```

Po każdym „Deploy HEAD Commit” cPanel uruchamia `.cpanel.yml` → `deploy/cpanel-deploy.sh`:
`composer install --no-dev`, `artisan migrate --force`, `artisan optimize`, kopiowanie `public/` do `public_html`.
Serwer nie potrzebuje Node.js: zbudowany frontend (`public/build`) jest w repozytorium.

## Każde wdrożenie (lokalnie)

```
npm run build
git add -A
git commit -m "opis zmian"
git push
```

Potem w cPanel: **Git Version Control → Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit**.

## Pierwsze wdrożenie (jednorazowo)

1. **Kopia public_html**: Menedżer plików → zaznacz zawartość `public_html` → Compress. Skrypt nadpisuje
   `index.php` i `.htaccess` (stare wersje zapisuje jako `*.bak-before-laravel`), inne pliki starej strony zostają.
2. **MultiPHP Manager**: ustaw PHP 8.4 dla domeny.
3. **Manage My Databases**: utwórz bazę i użytkownika, nadaj mu ALL PRIVILEGES do bazy.
4. **Dostęp do prywatnego repozytorium GitHub**:
   - cPanel → **SSH Access → Manage SSH Keys → Generate a New Key** (nazwa `id_rsa`, bez hasła).
   - **View/Download** klucz publiczny i dodaj go na GitHubie: repozytorium → Settings → Deploy keys → Add deploy key (tylko odczyt).
   - Clone URL: `git@github.com:LOGIN/REPO.git` (przy pytaniu o klucz hosta GitHub zaakceptuj go).
   - Jeśli hosting nie ma „SSH Access”: użyj HTTPS z tokenem fine-grained (tylko odczyt, tylko to repo):
     `https://TOKEN@github.com/LOGIN/REPO.git`.
5. **Git Version Control → Create**: Clone URL jak wyżej, Repository Path: `timemanager`.
6. **Menedżer plików** (Settings → Show Hidden Files): wgraj lokalny plik `.env.production` do `~/timemanager`
   jako `.env` i uzupełnij `APP_URL`, `DB_*`, `MAIL_*` (skrzynkę utwórz w cPanel → Email Accounts).
7. **Git Version Control → Manage → Pull or Deploy → Deploy HEAD Commit**.

Log wdrożenia: `~/.cpanel/logs/` (pliki `vc_*_git_deploy.log`).

## Uwagi

- Nie edytuj plików w `~/timemanager` przez Menedżer plików (poza `.env`): cPanel wdraża tylko czyste repozytorium.
- Kolejka działa w trybie `sync` (bez workera), więc maile wysyłają się od razu w trakcie żądania.
- Jeśli na serwerze brak Composera, wgraj `composer.phar` do katalogu domowego i wdróż ponownie.
