FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        unzip zip libicu-dev libcurl4-openssl-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl curl opcache zip \
    && pecl install opentelemetry \
    && docker-php-ext-enable opentelemetry \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG SERVICE_PATH
WORKDIR /app

COPY \${SERVICE_PATH}/composer.json ./composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

COPY \${SERVICE_PATH}/ ./

# Fail the image build if Symfony configuration or the kernel cannot boot.
RUN APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret PRICING_SERVICE_URL=http://pricing-service:8080 \
    php -r 'require "vendor/autoload.php"; $kernel = new \App\Kernel("prod", false); $kernel->boot(); $kernel->shutdown();'

ENV APP_ENV=dev
ENV APP_DEBUG=1

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
