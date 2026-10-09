#!/bin/sh
set -e

# Geliştirme ortamı: proje dizini host'tan bağlandığı için php-fpm, queue ve
# scheduler'ın oluşturduğu dosyalar (log, cache) birbirine yazılabilir olmalı.
umask 0000

if [ "$CONTAINER_ROLE" = "app" ]; then
    [ -f .env ] || cp .env.example .env
    [ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist
    grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
    chmod -R a+rwX storage bootstrap/cache
    php artisan migrate --force
    [ -L public/storage ] || php artisan storage:link
else
    # queue ve scheduler, app container'ı kurulumu bitirene kadar bekler.
    until [ -f vendor/autoload.php ] && grep -q '^APP_KEY=base64:' .env 2>/dev/null; do
        sleep 2
    done
fi

exec "$@"
