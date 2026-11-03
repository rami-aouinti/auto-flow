FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev libcurl4-openssl-dev \
    && docker-php-ext-install -j"$(nproc)" intl curl opcache \
    && pecl install opentelemetry \
    && docker-php-ext-enable opentelemetry \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG SERVICE_PATH
WORKDIR /app

COPY ${SERVICE_PATH}/composer.json ./composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

COPY ${SERVICE_PATH}/ ./

ENV APP_ENV=dev
ENV APP_DEBUG=1

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
