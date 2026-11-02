# NYX Messenger relay — PHP 8.2 Apache (non-root)
FROM php:8.2-apache-bookworm

RUN docker-php-ext-install opcache \
 && a2enmod rewrite headers \
 && sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/*.conf \
 && printf '<Directory /var/www/html/public>\n  AllowOverride All\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-available/nyx.conf \
 && a2enconf nyx

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
USER www-data
EXPOSE 8080
CMD ["apache2-foreground"]
