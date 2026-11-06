FROM ghcr.io/roadrunner-server/roadrunner:3.0.1 AS roadrunner

FROM php:8.4-cli-bookworm

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        unzip zip supervisor protobuf-compiler libicu-dev libcurl4-openssl-dev libzip-dev libonig-dev libpq-dev \
    && docker-php-ext-install -j"$(nproc)" intl curl mbstring opcache pdo_pgsql sockets zip \
    && pecl install opentelemetry \
    && docker-php-ext-enable opentelemetry \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apply the shared PHP runtime limits and OPcache settings to the RoadRunner
# microservice image as well as to the general-purpose development container.
COPY docker/php/conf.d/99-app.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/supervisor/offer-workers.conf /etc/supervisor/conf.d/offer-workers.conf

ARG SERVICE_PATH
ARG COMPOSER_NO_DEV=--no-dev
WORKDIR /app

# Resolve the local CoreBundle path repository inside the image.
COPY packages/core-bundle/ /packages/core-bundle/
COPY ${SERVICE_PATH}/composer.json ./composer.json
RUN composer install ${COMPOSER_NO_DEV} --no-interaction --prefer-dist --no-progress --no-scripts \
    && composer show autoflow/core-bundle --no-interaction \
    && test -f /packages/core-bundle/src/Security/JwtTokenVerifier.php \
    && test -f vendor/autoflow/core-bundle/src/Security/JwtTokenVerifier.php \
    && diff -qr /packages/core-bundle/src vendor/autoflow/core-bundle/src \
    || { echo "ERROR: installed autoflow/core-bundle does not match the CoreBundle source copied into the image."; exit 1; }

COPY ${SERVICE_PATH}/ ./

# TwigBundle is a development-only bundle for the local Swagger UI. Symfony Flex
# installs its recipe during dev dependency installation, so keep that config dev-only.
RUN if [ -f config/packages/twig.yaml ]; then \
        mkdir -p config/packages/dev && mv config/packages/twig.yaml config/packages/dev/twig.yaml; \
    fi

# Generate service-owned PHP Protobuf message classes from the service contracts.
# The RoadRunner ServiceInterface is kept explicit in source so its method names remain reviewable.
RUN if [ -d proto ]; then \
        find proto -name '*.proto' -print0 | xargs -0 -r protoc -I proto -I /usr/include --php_out=src; \
    fi \
    && apt-get purge -y protobuf-compiler \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# Fail the image build if Symfony configuration or the kernel cannot boot.
RUN APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret DATABASE_URL=postgresql://autoflow_catalog:local-only-catalog-password@127.0.0.1:5432/autoflow CATALOG_DATABASE_URL=postgresql://autoflow_catalog:local-only-catalog-password@127.0.0.1:5432/autoflow OFFER_DATABASE_URL=postgresql://autoflow_offer:build-only@127.0.0.1:5432/autoflow ORDER_DATABASE_URL=postgresql://autoflow_order:build-only@127.0.0.1:5432/autoflow ORDER_OFFER_SERVICE_URL=http://offer-service:8080 ORDER_PRICING_SERVICE_URL=http://pricing-service:8080 ORDER_CATALOG_SERVICE_URL=http://vehicle-catalog-service:8080 PRICING_SERVICE_URL=http://pricing-service:8080 AUTOFLOW_JWT_ISSUER=https://identity-build.invalid/realms/autoflow AUTOFLOW_JWT_JWKS_URI=http://127.0.0.1:8080/realms/autoflow/protocol/openid-connect/certs KEYCLOAK_TOKEN_URL=http://127.0.0.1:8080/token PRICING_CLIENT_ID=build-only PRICING_CLIENT_SECRET=build-only \
    php -r 'require "vendor/autoload.php"; $kernel = new \App\Kernel("prod", false); $kernel->boot(); $kernel->shutdown();'

# Resolve the generated Pricing gRPC adapter through Symfony DI during the image build.
RUN if [ -f src/Grpc/PricingGrpcService.php ]; then \
    APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret DATABASE_URL=postgresql://autoflow_pricing:build-only@127.0.0.1:5432/autoflow OFFER_DATABASE_URL=postgresql://autoflow_offer:build-only@127.0.0.1:5432/autoflow ORDER_DATABASE_URL=postgresql://autoflow_order:build-only@127.0.0.1:5432/autoflow ORDER_OFFER_SERVICE_URL=http://offer-service:8080 ORDER_PRICING_SERVICE_URL=http://pricing-service:8080 ORDER_CATALOG_SERVICE_URL=http://vehicle-catalog-service:8080 AUTOFLOW_JWT_ISSUER=https://identity-build.invalid/realms/autoflow AUTOFLOW_JWT_JWKS_URI=http://127.0.0.1:8080/realms/autoflow/protocol/openid-connect/certs KEYCLOAK_TOKEN_URL=http://127.0.0.1:8080/token PRICING_CLIENT_ID=build-only PRICING_CLIENT_SECRET=build-only php -r 'require "vendor/autoload.php"; $kernel = new \App\Kernel("prod", false); $kernel->boot(); $kernel->getContainer()->get(\App\Grpc\PricingGrpcService::class); $kernel->shutdown();'; \
    fi

# Boot both RoadRunner worker pools during the image build and verify the server config.
# A clean timeout is expected because the server is intentionally long-running.
RUN set +e; APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret DATABASE_URL=postgresql://autoflow_pricing:build-only@127.0.0.1:5432/autoflow OFFER_DATABASE_URL=postgresql://autoflow_offer:build-only@127.0.0.1:5432/autoflow ORDER_DATABASE_URL=postgresql://autoflow_order:build-only@127.0.0.1:5432/autoflow ORDER_OFFER_SERVICE_URL=http://offer-service:8080 ORDER_PRICING_SERVICE_URL=http://pricing-service:8080 ORDER_CATALOG_SERVICE_URL=http://vehicle-catalog-service:8080 PRICING_SERVICE_URL=http://pricing-service:8080 AUTOFLOW_JWT_ISSUER=https://identity-build.invalid/realms/autoflow AUTOFLOW_JWT_JWKS_URI=http://127.0.0.1:8080/realms/autoflow/protocol/openid-connect/certs KEYCLOAK_TOKEN_URL=http://127.0.0.1:8080/token PRICING_CLIENT_ID=build-only PRICING_CLIENT_SECRET=build-only \
    timeout --signal=TERM --kill-after=3s 8s rr serve -c .rr.yaml; status=$?; \
    if [ "$status" -ne 124 ] && [ "$status" -ne 137 ]; then exit "$status"; fi

RUN mkdir -p /app/var/cache /app/var/log /app/var/sessions \
    && chown -R www-data:www-data /app /packages/core-bundle

USER www-data

ENV APP_ENV=prod
ENV APP_DEBUG=0

EXPOSE 8080 9001

CMD ["rr", "serve", "-c", ".rr.yaml"]
