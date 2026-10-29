FROM php:8.3-fpm-alpine
RUN apk add --no-cache $PHPIZE_DEPS curl-dev libsodium-dev sqlite-dev oniguruma-dev \
    && docker-php-ext-install pdo pdo_sqlite sodium mbstring curl \
    && apk del $PHPIZE_DEPS
WORKDIR /var/www/nyx
COPY . /var/www/nyx
RUN mkdir -p storage/data storage/logs storage/cache storage/uploads storage/tmp data \
    && chown -R www-data:www-data storage data bootstrap/cache \
    && chmod -R 775 storage data bootstrap/cache
USER www-data
