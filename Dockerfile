FROM php:8.5-apache

# MongoDB-Treiber (ext-mongodb) und Werkzeuge für Composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends libssl-dev unzip git \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Render setzt PORT; Apache liest ihn in docker/ports.conf und docker/vhost.conf
ENV PORT=10000
WORKDIR /var/www/html

# Abhängigkeiten zuerst (besseres Layer-Caching)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-smv.ini
COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY . .
RUN chmod +x docker/entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
