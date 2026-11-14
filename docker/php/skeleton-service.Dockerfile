# Local bootstrap image for not-yet-implemented AutoFlow bounded contexts.
# Health-only scaffold; replace with the Symfony/RoadRunner runtime when business use cases are added.
FROM php:8.4-cli-alpine
ARG SERVICE_PATH
WORKDIR /app
COPY ${SERVICE_PATH}/public/ /app/public/
ENV APP_ENV=dev
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=5 CMD php -r '$c=stream_context_create(["http"=>["timeout"=>2]]); $r=@file_get_contents("http://127.0.0.1:8080/health/ready",false,$c); exit($r===false?1:0);'
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/public/index.php"]
