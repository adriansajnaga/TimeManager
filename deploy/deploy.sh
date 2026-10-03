#!/bin/bash
# Skrypt wdrożenia na cPanel (wywoływany z .cpanel.yml), na wzór Energieableseportal.
# Sam szuka PHP >= 8.4.1 i Composera, więc nie trzeba dopasowywać ścieżek do hostingu.
set -euo pipefail

APP="${APP:-$HOME/TimeManager}"
WEB="${WEB:-$HOME/public_html/tm}"

log() { echo "[deploy $(date '+%H:%M:%S')] $*"; }
fail() { echo "[deploy] BŁĄD: $*" >&2; exit 1; }

# --- PHP: preferowana wersja >= 8.4.1 ze wszystkimi rozszerzeniami ----------
log "Szukam wersji PHP"
REQUIRED_EXT="ctype curl dom fileinfo filter gd hash mbstring openssl pdo_mysql session tokenizer xml"
PHP=""
FALLBACK=""
for candidate in \
    /usr/local/bin/php85 /usr/local/bin/php84 \
    /usr/local/bin/ea-php85 /usr/local/bin/ea-php84 \
    /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php \
    /opt/alt/php85/usr/bin/php /opt/alt/php84/usr/bin/php \
    /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
    [ -n "$candidate" ] && [ -x "$candidate" ] || continue
    # Tylko PHP z linii komend (binarki CGI zwracają tu stronę "Security Alert").
    info=$("$candidate" -r 'if (PHP_SAPI !== "cli") exit(1); echo PHP_VERSION;' 2>/dev/null) || info=""
    if ! [[ "$info" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]]; then
        log "  $candidate: to nie PHP CLI, pomijam"
        continue
    fi
    if ! "$candidate" -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);' >/dev/null 2>&1; then
        log "  $candidate: PHP $info za stare"
        continue
    fi
    missing_here=""
    for ext in $REQUIRED_EXT; do
        "$candidate" -r "exit(extension_loaded('$ext') ? 0 : 1);" >/dev/null 2>&1 || missing_here="$missing_here $ext"
    done
    log "  $candidate: PHP $info, brakujące rozszerzenia:${missing_here:- brak}"
    FALLBACK="${FALLBACK:-$candidate}"
    if [ -z "$missing_here" ]; then
        PHP="$candidate"
        break
    fi
done
PHP="${PHP:-$FALLBACK}"
[ -n "$PHP" ] || fail "Nie znaleziono PHP >= 8.4.1. Włącz PHP 8.4 w cPanel (MultiPHP Manager)."
log "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

# --- Rozszerzenia PHP -------------------------------------------------------
missing=""
for ext in $REQUIRED_EXT; do
    "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);" || missing="$missing $ext"
done
# fileinfo (typy plików) i gd (obrazy w PDF) są potrzebne stronie, nie linii komend.
COMPOSER_IGNORE=""
for ext in fileinfo gd; do
    if [[ " $missing " == *" $ext "* ]]; then
        log "UWAGA: brak $ext w PHP CLI. Musi być włączone w PHP dla strony."
        missing="${missing/ $ext/}"
        COMPOSER_IGNORE="$COMPOSER_IGNORE --ignore-platform-req=ext-$ext"
    fi
done
[ -z "$missing" ] || fail "Brak rozszerzeń PHP:$missing. Poproś hosting o ich włączenie."

# --- Composer: własna, aktualna kopia (systemowy bywa za stary) -------------
COMPOSER="$APP/composer.phar"
COMPOSER_URL="https://getcomposer.org/download/latest-2.x/composer.phar"
if [ ! -s "$COMPOSER" ]; then
    log "Pobieram composer.phar"
    rm -f "$COMPOSER"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --retry 2 -o "$COMPOSER" "$COMPOSER_URL" || log "  curl nie zadziałał"
    fi
    if [ ! -s "$COMPOSER" ] && command -v wget >/dev/null 2>&1; then
        wget -q -O "$COMPOSER" "$COMPOSER_URL" || log "  wget nie zadziałał"
    fi
    if [ ! -s "$COMPOSER" ]; then
        "$PHP" -r "exit(@copy('$COMPOSER_URL', '$COMPOSER') ? 0 : 1);" || log "  pobieranie przez PHP nie zadziałało (allow_url_fopen?)"
    fi
    if [ ! -s "$COMPOSER" ] || ! "$PHP" "$COMPOSER" --version --no-ansi >/dev/null 2>&1; then
        rm -f "$COMPOSER"
        fail "Nie udało się pobrać composer.phar. Pobierz go z $COMPOSER_URL i wgraj ręcznie do $COMPOSER."
    fi
else
    "$PHP" "$COMPOSER" self-update --2 --no-interaction --quiet || log "composer self-update nie zadziałał, używam obecnej wersji"
fi
log "Composer: $("$PHP" "$COMPOSER" --version --no-ansi 2>/dev/null | head -1)"

# --- Warunki wstępne --------------------------------------------------------
[ -f "$APP/.env" ] || fail "Brak $APP/.env. Wgraj go przez Menedżer plików (zob. deploy/CPANEL.md)."
grep -q '^APP_KEY=base64:' "$APP/.env" || fail "APP_KEY w $APP/.env jest pusty. Lokalnie uruchom 'php artisan key:generate --show' i wpisz wynik."

# --- Instalacja -------------------------------------------------------------
cd "$APP"
export COMPOSER_HOME="${COMPOSER_HOME:-$HOME/.composer}"
log "composer install"
# --no-scripts: Composer uruchamia skrypty przez proc_open, które hosting wyłączył.
# Kroki z "post-autoload-dump" wykonujemy zaraz potem bez podprocesu.
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts $COMPOSER_IGNORE 2>&1
rm -f "$APP/bootstrap/cache/packages.php" "$APP/bootstrap/cache/services.php"
# Konfiguracja z cache poprzedniego wdrożenia może wskazywać inną bazę niż obecny .env,
# a migracje muszą trafić tam, gdzie po wdrożeniu będzie czytać strona.
# Tylko config:clear — optimize:clear czyści też cache w bazie, której przy pierwszym wdrożeniu jeszcze nie ma.
"$PHP" artisan config:clear --no-ansi 2>&1
"$PHP" artisan package:discover --no-ansi 2>&1

log "Kopiuję pliki publiczne do $WEB"
mkdir -p "$WEB"
rm -rf "$WEB/build"
cp -R "$APP/public/." "$WEB/"
cp "$APP/deploy/public_html/index.php" "$WEB/index.php"

chmod -R u+rwX "$APP/storage" "$APP/bootstrap/cache"

log "Baza danych (z .env)"
"$PHP" artisan db:show --no-ansi 2>&1 | head -10 || log "  db:show nie zadziałał"

log "Migracje"
"$PHP" artisan migrate --force --no-interaction 2>&1
"$PHP" artisan migrate:status --no-ansi 2>&1 | tail -4 || true

log "Buduję cache"
# Bez route:cache: w podkatalogu (/tm/) cache tras psuje stronę startową (405), jak w EM.
"$PHP" artisan optimize:clear 2>&1
"$PHP" artisan config:cache 2>&1
"$PHP" artisan event:cache 2>&1
"$PHP" artisan view:cache 2>&1

log "Gotowe."
