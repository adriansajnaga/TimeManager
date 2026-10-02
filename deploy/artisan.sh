#!/bin/bash
# Uruchamia "php artisan ..." odpowiednim PHP z linii komend (dla zadań Cron w cPanel).
# Przykład: /bin/bash /home/iascomm/TimeManager/deploy/artisan.sh queue:work --stop-when-empty
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PHP=""
FALLBACK=""
for candidate in \
    /usr/local/bin/php85 /usr/local/bin/php84 \
    /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php \
    /usr/local/bin/ea-php85 /usr/local/bin/ea-php84 \
    /opt/alt/php85/usr/bin/php /opt/alt/php84/usr/bin/php \
    /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
    [ -n "$candidate" ] && [ -x "$candidate" ] || continue
    version=$("$candidate" -r 'if (PHP_SAPI !== "cli" || PHP_VERSION_ID < 80401) exit(1); echo PHP_VERSION;' 2>/dev/null) || continue
    [[ "$version" =~ ^[0-9]+\.[0-9]+ ]] || continue
    FALLBACK="${FALLBACK:-$candidate}"
    if "$candidate" -r 'exit(extension_loaded("fileinfo") ? 0 : 1);' >/dev/null 2>&1; then
        PHP="$candidate"
        break
    fi
done
PHP="${PHP:-$FALLBACK}"

if [ -z "$PHP" ]; then
    echo "[artisan.sh] BŁĄD: nie znaleziono PHP CLI >= 8.4.1" >&2
    exit 1
fi

cd "$APP" && exec "$PHP" artisan "$@"
