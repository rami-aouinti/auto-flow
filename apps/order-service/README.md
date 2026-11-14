# AutoFlow Order Management Service

Order Management owns the lifecycle of a vehicle-leasing order. The Application layer validates commands and depends on an `OrderRepositoryInterface`; a Doctrine DBAL adapter owns SQL, transactions and tenant-local RLS context. It stores references to Customer, Offer, Pricing and Vehicle Catalog records plus immutable snapshots captured at order creation. It does not own customer profiles, vehicle taxonomy, commercial offer policy or pricing calculations.

## Local API

The service listens on host port **8085** (container port 8080). Every `/api/` route requires a Keycloak bearer token with audience `autoflow-order`, a verified tenant claim and the required realm role.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| POST | `/api/v1/orders` | `orders:write` | Create draft; requires `Idempotency-Key` |
| GET | `/api/v1/orders` | `orders:read` | List tenant orders |
| GET | `/api/v1/orders/{id}` | `orders:read` | Retrieve order and snapshots |
| POST | `/api/v1/orders/{id}/submit` | `orders:write` | Submit draft |
| POST | `/api/v1/orders/{id}/review` | `orders:manage` | Start manual review |
| POST | `/api/v1/orders/{id}/accept` | `orders:manage` | Accept |
| POST | `/api/v1/orders/{id}/reject` | `orders:manage` | Reject with reason |
| POST | `/api/v1/orders/{id}/cancel` | `orders:write` | Cancel when allowed |
| POST | `/api/v1/orders/{id}/contract-pending` | `orders:manage` | Await contract preparation |
| POST | `/api/v1/orders/{id}/complete` | `orders:manage` | Complete fulfilment |

Every lifecycle `POST` also requires an `Idempotency-Key` header. The key is scoped to tenant and authenticated actor. A repeated key with the same order, target status and reason returns the original response without adding another history row or Outbox event; reusing the key for a different command returns `409 Conflict`.

The full OpenAPI contract is in [docs/openapi.yaml](docs/openapi.yaml). Order creation requires `orders:write`, `customers:read`, `offers:read`, `pricing:quote` and `catalog:read` in the caller token because the API validates the references against their owning services. The caller token must also contain the `autoflow-customer` audience.

Example create body:

```json
{
  "offerId": "offer-123",
  "quoteId": "quote-456",
  "vehicleId": "variant-789",
  "customerId": "c4f0f45a-9f7a-4c87-9db0-9c5db6b7b001"
}
```

Order Management resolves all four snapshots itself before persisting the order. It calls Customer, Offer, Pricing and Vehicle Catalog with the caller's verified bearer token and tenant context. Customer must be active and belong to the caller's tenant; archived or unknown customers are rejected. The offer must be accepted and unexpired, the quote must belong to the same vehicle and remain valid, and the catalogue variant must be published. Client-supplied snapshots are overwritten and never trusted. The customer snapshot contains the minimal order-time identity/contact and billing/shipping address fields; consent records are never copied. The snapshot and customer version are immutable for the lifetime of the order. The local `order-operator` therefore needs `customers:read`, `offers:read`, `pricing:quote` and `catalog:read` in addition to the order roles; the local token client carries the corresponding upstream audiences. A 503 means an upstream dependency could not be reached and no order was created.

## Domain lifecycle

```text
DRAFT -> SUBMITTED -> UNDER_REVIEW -> ACCEPTED -> CONTRACT_PENDING -> COMPLETED
  |          |             |             |
  +----------+-------------+-------------+----> CANCELLED
                         UNDER_REVIEW -> REJECTED
```

The Domain model enforces allowed transitions. The application locks the order row and performs the state update, audit-history insert and Outbox insert in one PostgreSQL transaction.

## Data and reliability

- Dedicated PostgreSQL login `autoflow_order`; tenant RLS protects orders, history, idempotency and Outbox.
- Tenant context comes from the verified JWT principal and is set with transaction-local `set_config`; it is never trusted from the JSON body.
- Creation and every lifecycle transition require idempotency keys scoped to tenant and authenticated actor. Repeating the same key and Customer/Offer/quote/vehicle references replays the stored response before any upstream call; reusing it with different references is rejected. The idempotency fingerprint is based on stable references, not refreshed upstream snapshots.
- Customer, Offer, quote and vehicle snapshots are immutable through the lifecycle API. Customer references are UUIDs only; no cross-service foreign key or database join is used. Existing orders created before this migration retain a null customer reference, while all new order commands require one.
- Each state change appends an audit row and an integration event in the same transaction.
- The Outbox publisher has a separate `autoflow_order_publisher` login that can only execute narrow SECURITY DEFINER claim/ack/retry functions. Those functions are owned by a dedicated non-login BYPASSRLS role with access only to the Outbox table; neither runtime login receives BYPASSRLS. Retries stop after ten failed attempts and leave a terminal failed row for operator inspection.
- RabbitMQ delivery is at-least-once. The Payment event consumer validates the producer, schema version, event type, tenant and RabbitMQ metadata; its tenant-scoped Inbox stores a SHA-256 payload fingerprint. Exact redeliveries are ignored, while reuse of an `eventId` with different bytes is rejected to the DLQ. Transient database failures are requeued.
- Rejection requires a reason. Terminal states cannot transition further.
- CoreBundle propagates correlation IDs and verifies JWTs; OpenTelemetry exports traces and low-cardinality business counters.

## Local database setup

Compose runs the one-shot `postgres-bootstrap` service after PostgreSQL is healthy and before the Order/Payment APIs and workers start. It reapplies the idempotent Order, Payment, Billing and Customer schema migrations on both fresh and existing PostgreSQL volumes, including the Order customer-reference migration, payment-lifecycle migration and Outbox publisher grants.

To rerun the bootstrap explicitly after changing one of these SQL files:

```bash
docker compose --env-file .env.example run --rm postgres-bootstrap
```

The bootstrap also reconciles the local database role passwords with the connection URLs in `.env.example`. It does not delete or recreate the PostgreSQL volume.

Keycloak imports the realm file only when the `autoflow` realm does not already exist. If you already have that realm, the new client audience, roles and `order-operator` user will not be merged automatically. The order operator also needs the upstream read roles `customers:read`, `offers:read`, `pricing:quote` and `catalog:read`, plus the `autoflow-customer` audience mapper. For a disposable local Keycloak container, recreate it with `docker compose up -d --force-recreate keycloak` (this resets its local realm data); otherwise add the `autoflow-order` audience mapper, `orders:read/write/manage` realm roles and the `order-operator` user with `tenant_id=tenant-demo` manually.

Then rebuild and start the service:

```bash
docker compose --env-file .env.example up -d --build order-service order-outbox-publisher order-workflow-worker
docker compose ps order-service order-outbox-publisher
curl -i http://localhost:8085/health/ready
```

Do not use `docker compose down -v` on a database containing data.

## Observability

- Service name: `autoflow-order-service`.
- Business counters: `orders.created`, `orders.transitions` (the transition status is a bounded attribute).
- Trace service spans are correlated using W3C Trace Context; CoreBundle separately propagates `X-Correlation-ID`.
- Outbox publisher creates producer spans and records message ID/type/destination.
- Inspect queue backlog and oldest event age through RabbitMQ metrics; inspect traces in Grafana Tempo.

## Temporal lifecycle orchestration

The `order-workflow-worker` is a separate Go process using the official Temporal Go SDK. It consumes lifecycle events from the durable `autoflow.order.events` topic exchange through its own durable queue and starts one workflow per `(tenantId, orderId)`. Workflow IDs reject duplicate starts; events are acknowledged only after Temporal accepts the start or signal. Malformed messages are dead-lettered, while transient Temporal failures are requeued.

The workflow tracks the human-controlled order lifecycle without taking ownership of order state:

- 48 hours from submission to acceptance/rejection/cancellation;
- 24 hours from acceptance to `contract_pending`/cancellation;
- 7 days from `contract_pending` to completion/cancellation.

When a threshold expires, Temporal retries an activity that publishes a persistent `OrderWorkflowSlaBreached` event with publisher confirms. Alerts are retained in `autoflow.order.workflow.alerts` for an operational consumer/acknowledgement. Grafana dashboard `AutoFlow — Order Management` and Prometheus rules cover create/transition rates, Outbox failures, workflow backlog, dead-letter messages and SLA alerts.

**The workflow does not auto-approve orders, generate legal contracts, capture payments or perform e-signature.** Those actions require explicit business policy, customer data and provider integrations. The workflow only observes the authoritative lifecycle transitions and raises durable SLA events.
