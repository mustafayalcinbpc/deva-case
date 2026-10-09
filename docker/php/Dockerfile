# Aşamalar:
#   base   PHP eklentileri ve composer (geliştirme ve production ortak)
#   dev    geliştirme imajı; kod host'tan bağlanır (compose.yaml, target: dev)
#   assets arayüz asset'lerinin derlenmesi (npm ci && npm run build)
#   prod   production PHP imajı; kod, vendor ve public/build içinde (compose.prod.yaml)
#   web    production nginx imajı; public/ içinde (compose.prod.yaml)
# Derlemeye girmeyen dosyalar: docker/php/Dockerfile.dockerignore

FROM php:8.4-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql bcmath intl zip pcntl sockets redis opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- Geliştirme ------------------------------------------------------------------------------
FROM base AS dev

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- Production: arayüz asset'leri -----------------------------------------------------------
FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN npm run build

# --- Production: PHP -------------------------------------------------------------------------
FROM base AS prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/production.ini "$PHP_INI_DIR/conf.d/zz-production.ini"

# Bağımlılıklar kod değişmedikçe önbellekten gelir.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
COPY --from=assets /app/public/build public/build

# package:discover (post-autoload-dump) yalnızca kurulu paketleri bootstrap/cache'e yazar.
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.prod.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# php-fpm, queue ve scheduler aynı kullanıcıyla çalışır; storage'daki dosyalar herkese yazılabilir olur.
USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- Production: nginx -----------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/production.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public
