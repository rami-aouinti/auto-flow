# Notification Service — API Reference

**HTTP API:** None. This service is a background RabbitMQ worker, not a REST microservice.

## Messaging contract
- Consumes routing key `offer.published` from the AutoFlow events exchange.
- Currently accepts schemaVersion 2.
- Required event envelope fields: `eventId`, `tenantId`, `aggregate.id`, `data.createdBy`, `data.vehicleId`, `data.quoteId`, `data.expiresAt`, and `data.pricing`.
- Resolves offer owner's email through Keycloak Admin API, renders HTML/plain-text email and PDF attachment from immutable pricing snapshot, then sends via Symfony Mailer.
- Delivery state is persisted and event ID deduplication is used. RabbitMQ delivery is at-least-once; SMTP does not guarantee exactly-once delivery.

## Local operations
- SMTP: `smtp://mailpit:1025`
- Mailpit UI: http://localhost:8025
- Prometheus metrics: `http://notification-metrics:9464/metrics` (internal Docker network only)
- Queues: `notification.offer-published`, `notification.offer-published.retry`, `notification.failed`
- No public HTTP health endpoint is documented.
