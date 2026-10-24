
FROM dunglas/frankenphp:1.13.3-php8.4-bookworm

WORKDIR /app

RUN install-php-extensions \
    pdo_pgsql \
    mbstring \
    intl \
    zip \
    opcache

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /app/docker-start.sh

COPY Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 80

CMD ["/app/docker-start.sh"]
