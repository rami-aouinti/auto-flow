FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" mbstring pdo_pgsql sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY packages/core-bundle/ /packages/core-bundle/
COPY apps/fulfillment-service/composer.json ./composer.json

RUN COMPOSER_MAX_PARALLEL_HTTP=4 composer install --no-dev --ignore-platform-req=ext-opentelemetry --no-interaction --prefer-dist --no-progress --no-scripts

COPY apps/fulfillment-service/ ./

USER www-data

CMD ["php", "bin/consume-events.php"]
