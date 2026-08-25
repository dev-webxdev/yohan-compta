FROM php:8.4-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-install zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .
COPY docker/apache.conf /etc/apache2/sites-available/yohan-compta.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/yohan-compta.ini
COPY docker/entrypoint.sh /usr/local/bin/yohan-entrypoint

RUN composer dump-autoload --no-dev --optimize --no-interaction --no-scripts \
    && php artisan package:discover --ansi \
    && a2dissite 000-default \
    && a2ensite yohan-compta \
    && chmod +x /usr/local/bin/yohan-entrypoint \
    && chmod -R a+rX /var/www/html \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/up") === false ? 1 : 0);'

ENTRYPOINT ["yohan-entrypoint"]
CMD ["apache2-foreground"]
