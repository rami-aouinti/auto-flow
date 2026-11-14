FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev libonig-dev unzip && docker-php-ext-install pdo_pgsql sockets mbstring curl opcache && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY apps/notification-service/composer.json ./composer.json
RUN set -eu; \
    attempt=1; \
    until COMPOSER_MAX_PARALLEL_HTTP=2 COMPOSER_PROCESS_TIMEOUT=900 composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts; do \
      if [ "$attempt" -ge 5 ]; then echo "Composer install failed after 5 attempts"; exit 1; fi; \
      echo "Composer network/install failed; retrying ($attempt/4) in 10 seconds..."; \
      sleep 10; \
      attempt=$((attempt + 1)); \
    done
COPY apps/notification-service/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev
USER www-data
CMD ["php", "bin/consume-offer-events.php"]