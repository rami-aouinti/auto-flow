# AutoFlow Fulfillment Service

Fulfillment is the bounded context responsible for physical vehicle handover after the commercial prerequisites are satisfied. It owns preparation, delivery scheduling, carrier assignment, dispatch tracking and proof of delivery. It does not own orders, customer profiles, contracts, payments, invoices or vehicle catalogue data.

## Business boundary

A fulfillment is created once per tenant/order. It becomes eligible for preparation only after both independent prerequisites have been observed:

- `PaymentCaptured` from Payment Service.
- `ContractSigned` from Contract Service.

The events may arrive in either order and may be delivered more than once. The application layer must persist each event in a tenant-scoped Inbox, deduplicate by provider event ID, and update the prerequisite state in the same PostgreSQL transaction. The fulfillment must be created idempotently using the unique tenant/order key. A delivery must never start merely because an order was accepted or a payment was only authorized.

The first delivery integration will consume the versioned RabbitMQ exchanges already used by the platform. The event envelope must carry `eventId`, `eventType`, `occurredAt`, `tenantId`, `aggregateId` and `payload`. Consumers must reject malformed/unsupported versions, acknowledge only after commit, and route poison messages to a dead-letter queue.

## Lifecycle

```text
awaiting_prerequisites
  -> ready_for_preparation
  -> preparing
  -> ready_for_dispatch
  -> scheduled
  -> in_transit
  -> delivered

in_transit -> delivery_failed -> scheduled (reschedule)
non-terminal states -> cancelled (subject to business policy)
```

The domain aggregate enforces transitions. A delivered fulfillment is terminal and cannot be silently reopened. Corrections must be represented by an explicit compensating workflow/audit event.

## Ownership and integration

- Fulfillment stores only external identifiers and the minimum immutable delivery snapshot required for execution. It never joins another service's database.
- Customer contact/address and vehicle details are resolved through the owning service at the application boundary and snapshotted with provenance/version; event payloads are not trusted as authoritative PII unless the producing contract explicitly guarantees that snapshot.
- Order completion is requested from Order Service only after proof of delivery is committed locally. A failed upstream call is retried from a durable Outbox; the local delivery is not rolled back.
- Notifications are published as integration events; Notification Service owns channel delivery.
- No synchronous call to Payment or Contract is required in the delivery hot path.

## Initial implementation slice

This commit establishes the pure domain model and its unit tests before adding persistence, messaging, HTTP endpoints and runtime wiring. The domain has no Symfony, Doctrine, RabbitMQ or HTTP dependency. This keeps business rules testable without infrastructure.

## Planned API

- `GET /api/v1/fulfillments` — tenant-scoped paginated list.
- `GET /api/v1/fulfillments/{id}` — fulfillment details and current state.
- `POST /api/v1/fulfillments/{id}/start-preparation` — `fulfillments:manage`.
- `POST /api/v1/fulfillments/{id}/complete-preparation` — `fulfillments:manage`.
- `POST /api/v1/fulfillments/{id}/schedule` — carrier and delivery slot.
- `POST /api/v1/fulfillments/{id}/dispatch` — mark vehicle in transit.
- `POST /api/v1/fulfillments/{id}/confirm-delivery` — recipient and proof metadata.
- `POST /api/v1/fulfillments/{id}/delivery-failed` — reason and rescheduling workflow.

Every command will require an actor-scoped `Idempotency-Key`. All routes will require a verified Keycloak tenant claim and the `autoflow-fulfillment` audience.

## Quality gates

Before this service is considered operational, it must have PostgreSQL RLS, transactional Inbox/Outbox, at-least-once RabbitMQ consumption, idempotent commands, OpenAPI, readiness/liveness checks, unit and integration tests (including RLS and duplicate/out-of-order events), Compose wiring, Kubernetes manifests, observability and an end-to-end test from Payment + Contract through delivery completion.

Do not mark an order complete before a verified delivery confirmation is persisted. Do not store card data or duplicate the customer master record.
