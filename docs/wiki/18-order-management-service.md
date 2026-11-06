# 18 — Order Management bounded context

## Purpose and ownership

Order Management owns the submitted customer order and its lifecycle. It references Offer, Pricing and Vehicle Catalog identifiers but does not own their business models or persistence.

The order captures immutable snapshots of the offer, quote and vehicle facts accepted at creation. This protects the historical order from later catalogue or commercial changes.

## Implemented increment

The new independently deployable Symfony 7.4 application lives at `apps/order-service` and listens on host port 8085 in local Compose.

Implemented capabilities:
- Domain lifecycle aggregate and explicit transition policy.
- REST API for create, list, retrieve, submit, review, accept, reject, cancel, contract-pending and complete.
- Tenant-scoped PostgreSQL tables with forced row-level security.
- Immutable offer, quote and vehicle JSONB snapshots.
- Append-only lifecycle history.
- Create-request idempotency using a tenant-and-actor-scoped key, request hash and transaction-scoped PostgreSQL advisory lock.
- Transactional Outbox: state, history and event intent commit in the same local transaction.
- Narrow SECURITY DEFINER functions for cross-tenant Outbox claim/ack/retry; the service login does not have BYPASSRLS.
- RabbitMQ topic exchange plus a durable holding queue `autoflow.order.events`, persistent messages, publisher confirms and bounded exponential retry.
- JWT audience `autoflow-order`, tenant claim and `orders:read`, `orders:write`, `orders:manage` roles.
- Readiness database check, OpenTelemetry business counters and producer spans.

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

Events use a versioned envelope with `eventId`, `eventType`, `schemaVersion`, producer, aggregate identity, tenant ID, occurrence time and minimal business data. Current lifecycle event types include `OrderCreated`, `OrderSubmitted`, `OrderUnderReview`, `OrderAccepted`, `OrderRejected`, `OrderCancelled`, `OrderContractPending` and `OrderCompleted`.

Do not put customer PII, full documents, credentials or full quote snapshots into the shared event. Consumers must be idempotent because Outbox publication is at-least-once.

## Observability

OpenTelemetry instruments:
- `orders.create_requests`
- `orders.transitions` with bounded `status` attribute.

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
1. Calling Offer/Pricing/Catalog APIs to verify that referenced resources exist, are current and belong to the same tenant. The current API accepts snapshots from a trusted orchestrator; do not expose it directly to untrusted clients until validation is added.
2. Payment capture, credit/eligibility decision, contract document generation or e-signature.
3. A Temporal workflow. Temporal is a suitable next step for long-running partner checks, human review and contract progression, but this service currently exposes explicit state transitions and publishes events.
4. Downstream RabbitMQ consumers, dead-letter replay tooling, retention jobs and production secret rotation.
5. Full integration tests against real PostgreSQL RLS, RabbitMQ and Keycloak in the root CI pipeline.

These are explicit follow-up increments, not hidden assumptions.
