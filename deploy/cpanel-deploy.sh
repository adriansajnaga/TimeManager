#!/bin/bash
#
# Wdrożenie TM Time Manager na hostingu cPanel (uruchamiane przez .cpanel.yml).
#
# Kod aplikacji zostaje w katalogu repozytorium (np. ~/timemanager),
# a do public_html trafia tylko zawartość katalogu public/ z index.php
# wskazującym na katalog aplikacji. Szczegóły: deploy/CPANEL.md
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUBLIC_HTML="${PUBLIC_HTML:-$HOME/public_html}"

cd "$APP_DIR"

echo "==> Katalog aplikacji: $APP_DIR"
echo "==> Katalog publiczny: $PUBLIC_HTML"

if [ ! -f .env ]; then
    echo "BŁĄD: brak pliku $APP_DIR/.env. Wgraj go (zob. deploy/CPANEL.md) i wdróż ponownie." >&2
    exit 1
fi

# --- PHP >= 8.4.1 (systemowe, EasyApache ea-php albo CloudLinux alt-php) ---
PHP_BIN=""
for candidate in /usr/local/bin/php php \
    /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php \
    /opt/alt/php85/usr/bin/php /opt/alt/php84/usr/bin/php; do
    if command -v "$candidate" >/dev/null 2>&1 \
        && "$candidate" -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);' >/dev/null 2>&1; then
        PHP_BIN="$(command -v "$candidate")"
        break
    fi
done

if [ -z "$PHP_BIN" ]; then
    echo "BŁĄD: nie znaleziono PHP 8.4.1 lub nowszego dla linii komend." >&2
    exit 1
fi

echo "==> PHP: $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"

# --- Composer (wbudowany w cPanel, z PATH albo ~/composer.phar) ---
COMPOSER_PATH=""
for candidate in /opt/cpanel/composer/bin/composer "$(command -v composer 2>/dev/null || true)" "$HOME/composer.phar"; do
    if [ -n "$candidate" ] && [ -f "$candidate" ]; then
        COMPOSER_PATH="$candidate"
        break
    fi
done

if [ -z "$COMPOSER_PATH" ]; then
    echo "BŁĄD: nie znaleziono Composera. Wgraj composer.phar do katalogu domowego ($HOME) i wdróż ponownie." >&2
    exit 1
fi

composer() {
    if grep -q "__HALT_COMPILER" "$COMPOSER_PATH"; then
        "$PHP_BIN" "$COMPOSER_PATH" "$@"
    else
        # Skrypt-wrapper: podstaw wybrane PHP jako "php" w PATH
        local bin_dir
        bin_dir="$(mktemp -d)"
        ln -s "$PHP_BIN" "$bin_dir/php"
        PATH="$bin_dir:$PATH" "$COMPOSER_PATH" "$@"
        rm -rf "$bin_dir"
    fi
}

# --- Zależności PHP, baza danych, cache ---
echo "==> composer install"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> migracje"
"$PHP_BIN" artisan migrate --force

echo "==> optimize"
"$PHP_BIN" artisan optimize

# --- public_html ---
echo "==> publikacja plików do $PUBLIC_HTML"
mkdir -p "$PUBLIC_HTML"

# Przy pierwszym wdrożeniu zachowaj kopię dotychczasowej strony
for f in index.php .htaccess; do
    if [ -f "$PUBLIC_HTML/$f" ] && ! grep -q "TM Time Manager" "$PUBLIC_HTML/$f"; then
        cp "$PUBLIC_HTML/$f" "$PUBLIC_HTML/$f.bak-before-laravel"
    fi
done

# Zbudowane assety (Vite)
rm -rf "$PUBLIC_HTML/build"
cp -R public/build "$PUBLIC_HTML/build"

# Pozostałe pliki z public/ (favicon, robots.txt itd.)
find public -mindepth 1 -maxdepth 1 \
    ! -name index.php ! -name .htaccess ! -name build ! -name hot ! -name storage \
    -exec cp -R {} "$PUBLIC_HTML/" \;

# .htaccess Laravela + bloki wygenerowane przez cPanel (np. wybór wersji PHP)
CPANEL_BLOCKS=""
if [ -f "$PUBLIC_HTML/.htaccess" ]; then
    CPANEL_BLOCKS="$(awk '/BEGIN cPanel-generated/{p=1} p{print} /END cPanel-generated/{p=0}' "$PUBLIC_HTML/.htaccess")"
fi
{
    echo "# TM Time Manager - wygenerowane przez deploy/cpanel-deploy.sh"
    cat public/.htaccess
    if [ -n "$CPANEL_BLOCKS" ]; then
        printf '\n%s\n' "$CPANEL_BLOCKS"
    fi
} > "$PUBLIC_HTML/.htaccess.new"
mv "$PUBLIC_HTML/.htaccess.new" "$PUBLIC_HTML/.htaccess"

# index.php wskazujący na katalog aplikacji
{
    echo "<?php"
    echo ""
    echo "// TM Time Manager - wygenerowane przez deploy/cpanel-deploy.sh, nie edytuj."
    sed -e '1d' \
        -e "s#__DIR__.'/../#'$APP_DIR/#g" \
        -e 's#^\$app->handleRequest#$app->usePublicPath(__DIR__);\n\n&#' \
        public/index.php
} > "$PUBLIC_HTML/index.php.new"
mv "$PUBLIC_HTML/index.php.new" "$PUBLIC_HTML/index.php"

# Link do plików publicznych z storage/app/public
if [ ! -e "$PUBLIC_HTML/storage" ]; then
    ln -s "$APP_DIR/storage/app/public" "$PUBLIC_HTML/storage"
fi

echo "==> Gotowe."
