FROM ghcr.io/roadrunner-server/roadrunner:3.0.1 AS roadrunner

FROM php:8.4-cli-bookworm

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        unzip zip curl protobuf-compiler libicu-dev libcurl4-openssl-dev libzip-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" intl curl mbstring opcache sockets zip \
    && pecl install opentelemetry \
    && docker-php-ext-enable opentelemetry \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Pin and verify the RoadRunner PHP gRPC protoc plugin used to generate service stubs.
RUN curl -fsSL \
    https://github.com/roadrunner-server/roadrunner/releases/download/v3.0.1/protoc-gen-php-grpc-3.0.1-linux-amd64.tar.gz \
    -o /tmp/protoc-gen-php-grpc.tar.gz \
    && echo "8f60ad47f34a1627b37e488b6f496bb6afe82af47d46b3ac07d60ac5bd197b3a  /tmp/protoc-gen-php-grpc.tar.gz" | sha256sum -c - \
    && mkdir -p /tmp/protoc-plugin \
    && tar -xzf /tmp/protoc-gen-php-grpc.tar.gz -C /tmp/protoc-plugin \
    && plugin_path="$(find /tmp/protoc-plugin -type f -name 'protoc-gen-php-grpc*' -print -quit)" \
    && test -n "$plugin_path" \
    && install -m 0755 "$plugin_path" /usr/local/bin/protoc-gen-php-grpc \
    && rm -rf /tmp/protoc-plugin /tmp/protoc-gen-php-grpc.tar.gz

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG SERVICE_PATH
ARG COMPOSER_NO_DEV=--no-dev
WORKDIR /app

# Resolve the local CoreBundle path repository inside the image.
COPY packages/core-bundle/ /packages/core-bundle/
COPY ${SERVICE_PATH}/composer.json ./composer.json
RUN composer install ${COMPOSER_NO_DEV} --no-interaction --prefer-dist --no-progress --no-scripts

COPY ${SERVICE_PATH}/ ./

# Generate service-owned PHP messages and RoadRunner gRPC interfaces from .proto files.
RUN if [ -d proto ]; then \
        find proto -name '*.proto' -print0 | xargs -0 -r protoc -I proto -I /usr/include \
            --php_out=src \
            --plugin=protoc-gen-php-grpc=/usr/local/bin/protoc-gen-php-grpc \
            --php-grpc_out=src; \
    fi \
    && rm -f /usr/local/bin/protoc-gen-php-grpc \
    && apt-get purge -y protobuf-compiler curl \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# Fail the image build if Symfony configuration or the kernel cannot boot.
RUN APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret PRICING_SERVICE_URL=http://pricing-service:8080 \
    php -r 'require "vendor/autoload.php"; $kernel = new \App\Kernel("prod", false); $kernel->boot(); $kernel->shutdown();'

# Boot both RoadRunner worker pools during the image build and verify the server config.
# A clean timeout is expected because the server is intentionally long-running.
RUN set +e; APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret PRICING_SERVICE_URL=http://pricing-service:8080 \
    timeout --signal=TERM --kill-after=3s 8s rr serve -c .rr.yaml; status=$?; \
    if [ "$status" -ne 124 ] && [ "$status" -ne 137 ]; then exit "$status"; fi

ENV APP_ENV=dev
ENV APP_DEBUG=1

EXPOSE 8080 9001

CMD ["rr", "serve", "-c", ".rr.yaml"]
