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

The worker consumes routing key offer.published and currently accepts schemaVersion 2. The email is sent to the Keycloak account that created the offer. The envelope must contain eventId, tenantId, aggregate.id, data.createdBy, data.vehicleId, data.quoteId, data.expiresAt, and data.pricing.

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


## Tenant-scoped Mercure event bridge

The companion `mercure-event-bridge` container runs `bin/relay-to-mercure.php`. It consumes the durable service event exchanges, requires a valid tenant identifier from the event envelope or AMQP headers, and publishes a minimal event summary to the private topic `https://autoflow.local/tenants/{tenantId}/events`. It deliberately does not forward raw event payloads or personal data. Invalid envelopes are dead-lettered; transient hub failures are requeued.

The publisher JWT is created inside the worker for one topic and a 60-second lifetime. Customer Service separately signs five-minute subscriber JWTs after validating the Keycloak principal and tenant, then sets the HttpOnly `mercureAuthorization` cookie. Neither signing key is exposed to the browser. Configure identical strong `MERCURE_PUBLISHER_JWT_KEY` and `MERCURE_SUBSCRIBER_JWT_KEY` values on the hub and their respective backend services. The local topic base is `https://autoflow.local/tenants`.
