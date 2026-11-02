# NYX Messenger relay — PHP 8.2 Apache (shared-hosting compatible layout)
FROM php:8.2-apache-bookworm

RUN docker-php-ext-install opcache \
 && a2enmod rewrite headers \
 && sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html/public>\n  AllowOverride All\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-available/nyx.conf \
 && a2enconf nyx

WORKDIR /var/www/html
COPY . /var/www/html/

# Data outside web root
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
EXPOSE 80
