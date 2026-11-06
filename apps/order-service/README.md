# AutoFlow Order Management Service

Order Management owns the lifecycle of a vehicle-leasing order. The Application layer validates commands and depends on an `OrderRepositoryInterface`; a Doctrine DBAL adapter owns SQL, transactions and tenant-local RLS context. It stores references to Offer, Pricing and Vehicle Catalog records plus immutable snapshots captured at order creation. It does not own vehicle taxonomy, commercial offer policy or pricing calculations.

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

The full OpenAPI contract is in [docs/openapi.yaml](docs/openapi.yaml). Order creation requires `orders:write`, `offers:read`, `pricing:quote` and `catalog:read` in the caller token because the API validates the references against their owning services.

Example create body:

```json
{
  "offerId": "offer-123",
  "quoteId": "quote-456",
  "vehicleId": "variant-789"
}
```

Order Management now resolves all three snapshots itself before persisting the order. It calls Offer, Pricing and Vehicle Catalog with the caller's verified bearer token and tenant context. The offer must be accepted and unexpired, the quote must belong to the same vehicle and remain valid, and the catalogue variant must be published. Client-supplied snapshots are overwritten and never trusted. The local `order-operator` therefore needs `offers:read`, `pricing:quote` and `catalog:read` in addition to the order roles; the local token client already carries the three upstream audiences. A 503 means an upstream dependency could not be reached and no order was created.

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
- Creation and every lifecycle transition require idempotency keys scoped to tenant and authenticated actor. Repeating the same key and Offer/quote/vehicle references replays the stored response before any upstream call; reusing it with different references is rejected. The idempotency fingerprint is based on stable references, not refreshed upstream snapshots.
- Offer, quote and vehicle snapshots are immutable through the lifecycle API.
- Each state change appends an audit row and an integration event in the same transaction.
- The Outbox publisher has a separate `autoflow_order_publisher` login that can only execute narrow SECURITY DEFINER claim/ack/retry functions. Those functions are owned by a dedicated non-login BYPASSRLS role with access only to the Outbox table; neither runtime login receives BYPASSRLS. Retries stop after ten failed attempts and leave a terminal failed row for operator inspection.
- RabbitMQ delivery is at-least-once. Consumers must deduplicate by `eventId`.
- Rejection requires a reason. Terminal states cannot transition further.
- CoreBundle propagates correlation IDs and verifies JWTs; OpenTelemetry exports traces and low-cardinality business counters.

## Local database setup

For a **fresh** PostgreSQL volume, Compose mounts the role and schema scripts into the initialization directory. For an existing volume, apply them once manually:

```bash
docker compose exec -T postgres psql -U autoflow -d autoflow -v ON_ERROR_STOP=1 -f /docker-entrypoint-initdb.d/120-order-role.sql
docker compose exec -T postgres psql -U autoflow -d autoflow -v ON_ERROR_STOP=1 -f /docker-entrypoint-initdb.d/130-order-schema.sql
docker compose exec -T postgres psql -U autoflow -d autoflow -v ON_ERROR_STOP=1 -f /docker-entrypoint-initdb.d/140-order-transition-idempotency.sql
```

Keycloak imports the realm file only when the `autoflow` realm does not already exist. If you already have that realm, the new client audience, roles and `order-operator` user will not be merged automatically. The order operator also needs the upstream read roles `offers:read`, `pricing:quote` and `catalog:read`. For a disposable local Keycloak container, recreate it with `docker compose up -d --force-recreate keycloak` (this resets its local realm data); otherwise add the `autoflow-order` audience mapper, `orders:read/write/manage` realm roles and the `order-operator` user with `tenant_id=tenant-demo` manually.

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
