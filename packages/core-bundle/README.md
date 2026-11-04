# AutoFlow CoreBundle

Shared **technical** foundation for AutoFlow Symfony microservices. The bundle standardizes cross-cutting mechanics without creating a shared business domain.

## Included capabilities

| Capability | Included behavior | Activation |
| --- | --- | --- |
| Correlation context | Validates or generates `X-Correlation-ID`, stores it for the request, returns it in the response and clears it after the request | Enabled by default |
| HTTP propagation | Adds the current correlation ID to outgoing Symfony HttpClient requests unless the caller already supplied the header | Enabled by default |
| Health endpoints | `GET /health/live` and `GET /health/ready`; readiness aggregates tagged health checks | Enabled by default |
| Messenger propagation | Stamp and middleware carry correlation IDs through message handling and restore worker context | Add middleware to each configured bus |
| Integration event envelope | Event ID, name, aggregate ID, occurrence time, schema version, payload, correlation and causation IDs | Use when publishing integration events |
| Problem Details | Factory for safe RFC 9457-style `application/problem+json` responses | Inject where an API adapter maps known errors |

The bundle does not configure a broker, create queues, publish events automatically, or claim that a database Outbox exists. Those are application/infrastructure responsibilities.

## Installation in this monorepo

Each service remains an independent Composer project. Its `composer.json` uses the local path repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../../packages/core-bundle",
      "options": { "symlink": false }
    }
  ],
  "require": {
    "autoflow/core-bundle": "^1.0"
  }
}
```

The service Docker build copies `packages/core-bundle` to `/packages/core-bundle` before Composer resolves dependencies. From `/app`, the same relative path resolves to that copied package. This keeps the final image self-contained and avoids a symlink to a host-only path.

Register the bundle in `config/bundles.php`:

```php
AutoFlow\\CoreBundle\\CoreBundle::class => ['all' => true],
```

Configure it in `config/packages/autoflow_core.yaml`:

```yaml
autoflow_core:
    correlation_id: true
    correlation_header: X-Correlation-ID
```

Import the health routes in `config/routes/autoflow_core.yaml`:

```yaml
autoflow_core:
    resource: '@CoreBundle/Resources/config/routes.yaml'
```

The existing service `/health` endpoint is intentionally left unchanged for Docker Compose compatibility. The bundle adds `/health/live` and `/health/ready`.

## Readiness checks

Implement `HealthCheckInterface` in a service-specific Infrastructure class. The bundle autoconfigures the tag.

```php
final class DatabaseHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthCheckResult
    {
        // Execute a bounded, read-only connectivity check here.
        return HealthCheckResult::healthy();
    }
}
```

Do not return credentials, connection strings, SQL, hostnames or exception messages in public health responses. Keep checks fast and bounded. A service with no registered checks reports ready; register real dependency checks before relying on readiness in an orchestrated deployment.

## Messenger

Add the middleware to the bus that carries integration messages:

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - AutoFlow\\CoreBundle\\Messenger\\CorrelationIdMiddleware
```

When dispatching from an HTTP request, the middleware adds a `CorrelationIdStamp` if one is not already present. On consumption, it restores the stamped ID for the duration of handling and restores the previous worker context in a `finally` block.

A message envelope is not an Outbox. For reliable publication, the producer must persist business state and an outbox record in one database transaction, then publish asynchronously with idempotent consumers.

## HTTP error responses

Use `ProblemDetailsFactory` in the transport adapter after mapping a known application error to an HTTP status. Do not expose exception messages, stack traces, SQL details or internal service names. The bundle deliberately does not convert every exception globally: each service must decide which failures are validation errors, conflicts, not-found responses or unexpected server errors.

## Integration event contract

Use `IntegrationEvent::create()` to create a transport-neutral envelope. Keep event payloads composed of stable primitives/arrays and version the schema. Do not place Doctrine entities, Symfony requests, framework objects or domain objects from another bounded context in the payload.

Example metadata:

```json
{
  "eventId": "generated-uuid",
  "eventName": "pricing.quote.calculated",
  "aggregateId": "quote-123",
  "occurredAt": "2026-11-04T00:00:00+00:00",
  "schemaVersion": 1,
  "correlationId": "request-or-workflow-correlation",
  "causationId": "optional-parent-event",
  "payload": {}
}
```

The event name and payload belong to the publishing bounded context. Consumers translate the contract into their own application commands; they must not import the producer's domain classes.

## Observability and security boundaries

AutoFlow already enables OpenTelemetry PHP auto-instrumentation and exports traces to Tempo. The bundle propagates a separate application correlation ID for logs, support and message-level diagnostics. A correlation ID is **not** a trace ID, authentication token, tenant ID or authorization decision.

Use W3C Trace Context for distributed tracing. Keep OpenTelemetry SDK/exporter configuration in deployment/service configuration so that the bundle does not force a vendor or collector endpoint. Do not attach customer PII, tokens, payment data or secrets to correlation metadata, logs, event headers or spans.

## What intentionally stays outside this bundle

- Vehicle, Offer, Pricing, Customer, Contract, Order and Payment models/value objects.
- Domain events and business policies owned by each bounded context.
- Doctrine entities, repositories, migrations and database schemas.
- Transactional Outbox persistence and relay workers.
- Broker topology, routing keys, retry policies and dead-letter queues.
- Temporal workflows, workflow/activity implementations and SDK lifecycle.
- Authentication, authorization, tenant isolation and business-specific rate limits.
- Service-specific HTTP clients, partner credentials and business orchestration.

Temporal is reserved for durable, long-running workflows. Messenger remains appropriate for independent asynchronous jobs and event delivery. The CoreBundle supplies only neutral technical primitives; it does not make a service depend on either transport unless that service chooses to use it.
