# AutoFlow Order Management Service

Order Management owns the lifecycle of a vehicle-leasing order. It stores references to Offer, Pricing and Vehicle Catalog records plus immutable snapshots captured at order creation. It does not own vehicle taxonomy, commercial offer policy or pricing calculations.

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

The full OpenAPI contract is in [docs/openapi.yaml](docs/openapi.yaml).

Example create body:

```json
{
  "offerId": "offer-123",
  "quoteId": "quote-456",
  "vehicleId": "variant-789",
  "offerSnapshot": { "offerVersion": 1, "validUntil": "2026-11-13T12:00:00Z" },
  "quoteSnapshot": { "currency": "EUR", "monthlyAmount": "329.00", "durationMonths": 48 },
  "vehicleSnapshot": { "manufacturer": "Example Motors", "model": "E-1", "variant": "Long Range" }
}
```

The snapshots preserve the submitted commercial and vehicle facts even if upstream data changes later. **Current boundary:** this first increment accepts snapshots from its trusted caller; it does not yet call Offer/Pricing/Catalog to verify quote ownership, validity or tenant consistency. Do not expose this endpoint to an untrusted public client until that orchestration/validation boundary is implemented.

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
- Creation requires an idempotency key. Repeating the same key and payload replays the stored response; reusing it with a different payload is rejected.
- Offer, quote and vehicle snapshots are immutable through the lifecycle API.
- Each state change appends an audit row and an integration event in the same transaction.
- The Outbox publisher uses a narrow SECURITY DEFINER claim/ack/retry API; the runtime role does not receive BYPASSRLS.
- RabbitMQ delivery is at-least-once. Consumers must deduplicate by `eventId`.
- Rejection requires a reason. Terminal states cannot transition further.
- CoreBundle propagates correlation IDs and verifies JWTs; OpenTelemetry exports traces and low-cardinality business counters.

## Local database setup

For a **fresh** PostgreSQL volume, Compose mounts the role and schema scripts into the initialization directory. For an existing volume, apply them once manually:

```bash
docker compose exec -T postgres psql -U autoflow -d autoflow -v ON_ERROR_STOP=1 -f /docker-entrypoint-initdb.d/120-order-role.sql
docker compose exec -T postgres psql -U autoflow -d autoflow -v ON_ERROR_STOP=1 -f /docker-entrypoint-initdb.d/130-order-schema.sql
```

Then rebuild and start the service:

```bash
docker compose --env-file .env.example up -d --build order-service order-outbox-publisher
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

## Explicitly not included yet

Temporal workflow orchestration, automatic upstream offer/quote verification, customer identity/profile storage, payment capture, contract generation/signature, and downstream event consumers are later increments. The Temporal server already present in Compose is not proof that this service currently runs a Temporal workflow.
