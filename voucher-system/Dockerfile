FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring pdo_mysql \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/
COPY deployment/apache-security.conf /etc/apache2/conf-available/voucher-security.conf

RUN a2enconf voucher-security \
    && mkdir -p /var/lib/php/sessions /var/www/html/uploads \
    && chown -R www-data:www-data /var/lib/php/sessions /var/www/html/uploads \
    && chmod 0700 /var/lib/php/sessions \
    && chmod 0775 /var/www/html/uploads

EXPOSE 80

CMD ["apache2-foreground"]
