FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip zip libicu-dev libpq-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        intl opcache pcntl pdo_pgsql sockets zip \
    && pecl channel-update pecl.php.net \
    && pecl install xdebug \
    && pecl install opentelemetry \
    && docker-php-ext-enable xdebug opentelemetry \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/auto-flow

COPY docker/php/conf.d/99-app.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/conf.d/99-xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini

COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-progress

COPY . .
RUN chown -R www-data:www-data /var/www/auto-flow
USER www-data

CMD ["sh", "-lc", "while :; do sleep 3600; done"]
