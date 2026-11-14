FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev && docker-php-ext-install -j"$(nproc)" pdo_pgsql && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app
RUN mkdir -p /app/var && chown -R www-data:www-data /app
USER www-data
EXPOSE 8080
CMD ["php","-S","0.0.0.0:8080","-t","public","public/index.php"]
