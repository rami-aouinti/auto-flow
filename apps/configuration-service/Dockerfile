FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev ca-certificates \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY public/ /app/public/
COPY migrations/ /app/migrations/
COPY bin/ /app/bin/
RUN mkdir -p /var/lib/autoflow/configuration && chown -R www-data:www-data /app /var/lib/autoflow
USER www-data
ENV APP_ENV=prod
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 CMD php -r '$c=stream_context_create(["http"=>["timeout"=>2]]); $r=@file_get_contents("http://127.0.0.1:8080/health/ready",false,$c); exit($r===false?1:0);'
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/public/index.php"]
