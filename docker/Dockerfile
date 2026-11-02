# NYX Relay — production-ish PHP node image (non-root)
FROM php:8.2-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libsqlite3-dev libzip-dev unzip curl \
    && docker-php-ext-install pdo pdo_sqlite opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/nyx/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/*.conf

WORKDIR /var/www/nyx
COPY . /var/www/nyx

RUN mkdir -p /var/www/nyx/data /var/www/nyx/storage/logs \
 && chown -R www-data:www-data /var/www/nyx \
 && chmod -R 750 /var/www/nyx/data

RUN printf '<Directory /var/www/nyx/public>\n  AllowOverride All\n  Require all granted\n</Directory>\n' \
  > /etc/apache2/conf-available/nyx.conf \
 && a2enconf nyx

# Apache can start as www-data when bound to 8080
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1:8080/api/v3/ops/health || exit 1
CMD ["apache2-foreground"]
