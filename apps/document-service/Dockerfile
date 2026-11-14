FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libmagic1 libonig-dev libcurl4-openssl-dev libzip-dev ca-certificates \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql fileinfo mbstring curl zip opcache \
    && rm -rf /var/lib/apt/lists/*
RUN printf '%s\n' 'upload_max_filesize=25M' 'post_max_size=26M' 'max_execution_time=120' 'max_input_time=60' > /usr/local/etc/php/conf.d/document-service.ini
WORKDIR /app
COPY public/ /app/public/
COPY migrations/ /app/migrations/
COPY src/ /app/src/
COPY docs/ /app/docs/
COPY bin/ /app/bin/
RUN mkdir -p /var/lib/autoflow/documents && chown -R www-data:www-data /app /var/lib/autoflow
USER www-data
ENV APP_ENV=prod DOCUMENT_STORAGE_PATH=/var/lib/autoflow/documents
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/public/index.php"]
