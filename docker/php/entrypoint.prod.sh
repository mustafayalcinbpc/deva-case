#!/bin/sh
# Production açılışı (compose.prod.yaml). Kod ve bağımlılıklar imajdadır; storage named volume'dadır.
set -e

# Volume eski bir imajla oluşturulmuşsa eksik dizinler tamamlanır.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

# Config, route, event ve view önbellekleri ortam değişkenlerinden üretilir. Her container kendi
# bootstrap/cache'ini kullanır; derlenmiş view'lar ortak storage volume'ündedir.
php artisan optimize

if [ "$CONTAINER_ROLE" = "app" ]; then
    php artisan migrate --force
    # Yalnızca DEMO_SEED=true ise ve veritabanı boşsa demo verisi yüklenir (varsayılan: kapalı).
    php artisan demo:seed
fi

exec "$@"
