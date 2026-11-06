# AutoFlow Notification Service

Asynchronous notification worker for the AutoFlow offer lifecycle.

## Responsibilities

- Consume durable OfferPublished events from the autoflow.events topic exchange.
- Resolve the offer owner's email through Keycloak Admin API using a dedicated service account.
- Render responsive HTML and plain-text email content.
- Generate a PDF attachment from the immutable pricing snapshot carried by the event.
- Send through Symfony Mailer (Mailpit locally; authenticated SMTP in production).
- Persist delivery state and deduplicate by event ID.
- Retry transient failures with a 30-second delay, up to five attempts, then route to notification.failed.
- Emit structured JSON logs and expose delivery-state gauges for Prometheus.

## Event contract

The worker consumes routing key offer.published. The envelope must contain eventId, tenantId, aggregate.id, data.createdBy, data.vehicleId, data.quoteId, data.expiresAt, and data.pricing.

The pricing object is a copy of the immutable snapshot stored by Offer Service. This avoids a service-to-service call with a user's access token and prevents the worker from bypassing owner and tenant authorization on the public PDF endpoint.

## Local development

- SMTP: smtp://mailpit:1025
- Mailpit UI: http://localhost:8025
- Prometheus metrics: http://notification-metrics:9464/metrics (internal Docker network)
- RabbitMQ queues: notification.offer-published, notification.offer-published.retry, notification.failed

The local Keycloak realm provisions the autoflow-notification confidential client with a service account limited to query-users and view-users in the realm-management client.

## Delivery semantics

RabbitMQ delivery is at-least-once. The unique event ID and delivery table prevent normal redeliveries after a successful send. SMTP does not provide a universal exactly-once guarantee: a process crash after the SMTP server accepts a message but before the database records sent can still result in a duplicate on retry. A provider with idempotency-key support should be used for stronger guarantees in production.

## Production configuration

Set a dedicated database role and URL, strong Keycloak service-account secret, and authenticated TLS SMTP DSN via secrets. Do not reuse local realm credentials or Mailpit in production.
