FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev && docker-php-ext-install -j"$(nproc)" pdo_pgsql && rm -rf /var/lib/apt/lists/*
WORKDIR /app
ARG SERVICE_PATH
COPY ${SERVICE_PATH}/ /app/
RUN mkdir -p /app/var && chown -R www-data:www-data /app
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=5s --start-period=15s --retries=5 CMD php -r '$c=stream_context_create(["http"=>["timeout"=>3]]); $r=@file_get_contents("http://127.0.0.1:8080/health/ready",false,$c); exit($r===false?1:0);'
CMD ["php","-S","0.0.0.0:8080","-t","public","public/index.php"]
