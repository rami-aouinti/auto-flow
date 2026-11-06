# 18 — Order Management bounded context

## Purpose and ownership

Order Management owns the submitted customer order and its lifecycle. It references Offer, Pricing and Vehicle Catalog identifiers but does not own their business models or persistence.

The order captures immutable snapshots of the offer, quote and vehicle facts accepted at creation. This protects the historical order from later catalogue or commercial changes.

## Implemented increment

The new independently deployable Symfony 7.4 application lives at `apps/order-service` and listens on host port 8085 in local Compose. The Application layer depends on `OrderRepositoryInterface`; the Doctrine DBAL adapter owns transactions and SQL so persistence does not leak into the use-case layer.

Implemented capabilities:
- Domain lifecycle aggregate and explicit transition policy.
- REST API for create, list, retrieve, submit, review, accept, reject, cancel, contract-pending and complete.
- Before creation, resolves Offer, Pricing and Vehicle Catalog over authenticated HTTP; verifies accepted/unexpired offer, quote-to-vehicle consistency and published catalogue variant; persists only server-resolved immutable snapshots.
- Tenant-scoped PostgreSQL tables with forced row-level security.
- Immutable offer, quote and vehicle JSONB snapshots; PostgreSQL column-level grants prevent the API role from updating snapshot columns.
- Append-only lifecycle history.
- Create-request idempotency using a tenant-and-actor-scoped key, request hash and transaction-scoped PostgreSQL advisory lock.
- Transactional Outbox: state, history and event intent commit in the same local transaction.
- Narrow SECURITY DEFINER functions for cross-tenant Outbox claim/ack/retry; the service login does not have BYPASSRLS.
- RabbitMQ topic exchange plus a durable holding queue `autoflow.order.events`, persistent messages, publisher confirms and bounded exponential retry.
- Separate Go Temporal worker consuming lifecycle events with manual acknowledgements, idempotent workflow IDs, dead-letter handling and durable SLA timers.\n- Separate Go Temporal worker consuming lifecycle events with manual acknowledgements, idempotent workflow IDs, dead-letter handling and durable SLA timers.
- JWT audience `autoflow-order`, tenant claim and `orders:read`, `orders:write`, `orders:manage` roles.
- Readiness database check, OpenTelemetry business counters and producer spans that continue the originating HTTP trace through the Outbox into RabbitMQ.

## Lifecycle

```text
DRAFT -> SUBMITTED -> UNDER_REVIEW -> ACCEPTED -> CONTRACT_PENDING -> COMPLETED
  |          |             |             |
  +----------+-------------+-------------+----> CANCELLED
                         UNDER_REVIEW -> REJECTED
```

The Domain aggregate rejects illegal transitions. The application locks the row with `SELECT ... FOR UPDATE`, then writes the new status, audit row and integration event atomically.

## API and permissions

See [the service README](../../apps/order-service/README.md) and [OpenAPI contract](../../apps/order-service/docs/openapi.yaml).

- Create/list/read: `orders:write` / `orders:read`.
- Submit/cancel: `orders:write`.
- Review/accept/reject/contract-pending/complete: `orders:manage`.
- All API requests require a verified bearer token and tenant context from the authenticated principal.
- Rejection requires a reason of at most 500 characters.

## Data ownership

| Table | Purpose |
|---|---|
| `orders` | Current state and immutable snapshots |
| `order_history` | Append-only state transition audit |
| `order_idempotency` | Idempotent create response replay |
| `order_outbox` | Events awaiting broker publication |

Every table is protected by forced PostgreSQL RLS. The application sets `app.tenant_id` transaction-locally, preventing tenant context leakage in RoadRunner's long-lived workers.

## Event contract

Events use a versioned envelope with `eventId`, `eventType`, `schemaVersion`, producer, aggregate identity, tenant ID, occurrence time and minimal business data. Current lifecycle event types include `OrderCreated`, `OrderSubmitted`, `OrderUnderReview`, `OrderAccepted`, `OrderRejected`, `OrderCancelled`, `OrderContractPending` and `OrderCompleted`. The workflow worker additionally publishes `OrderWorkflowSlaBreached` when a lifecycle deadline expires.

Do not put customer PII, full documents, credentials or full quote snapshots into the shared event. Consumers must be idempotent because Outbox publication is at-least-once.

## Temporal workflow

`order-workflow-worker` starts one durable Temporal workflow per tenant/order when `OrderSubmitted` arrives, then signals it for later lifecycle events. It observes the existing Order API transitions rather than writing order state itself. It raises SLA events after 48 hours without a decision, 24 hours without contract-pending progression, and 7 days without fulfilment. The alerts queue is monitored by Prometheus and the Order Management Grafana dashboard.

The workflow does not perform automatic credit approval, payment capture, contract generation or e-signature. Those actions require explicit business rules, customer identity data and provider integrations.

## Observability

OpenTelemetry instruments:
- `orders.create_requests`
- `orders.transitions` with bounded `status` attribute
- `order_outbox.published` with bounded `event_type` attribute
- `order_outbox.publish_failures` with bounded `event_type` attribute

The API's HTTP spans are correlated by W3C Trace Context. The publisher creates producer spans and injects trace context into RabbitMQ message headers. CoreBundle's correlation ID remains a separate operational identifier.

Recommended dashboards:
- Order create requests and lifecycle transitions.
- Order API 4xx/5xx and p95 latency.
- Outbox pending count and oldest pending age.
- Publisher retries and failures.
- RabbitMQ ready/unacked messages.
- Database connection saturation and query latency.

## Deliberate boundaries and remaining work

This increment does **not** claim the following are implemented:
1. Production delegated-token/exchange policy and least-privilege scope provisioning for each customer/partner client.
2. Payment capture, credit/eligibility decision, contract document generation or e-signature.
3. A business consumer that acknowledges/clears SLA alert messages, dead-letter replay tooling, retention jobs and production secret rotation.
4. Full integration tests against real PostgreSQL RLS, RabbitMQ, Keycloak and Temporal in the root CI pipeline.

These are explicit follow-up increments, not hidden assumptions.
