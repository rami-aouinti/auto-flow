# AutoFlow Audit Service

Tenant-isolated, append-only audit journal for compliance, investigations and operational traceability.

## Responsibilities and boundaries

- Stores immutable audit facts; it does not own or mutate orders, offers, payments or contracts.
- Every event is tenant-scoped and carries actor, action, resource, timestamp and correlation context.
- Uses PostgreSQL row-level security and a per-tenant SHA-256 hash chain to make tampering detectable.
- Redacts common credential and personal-data fields before persistence.
- Uses the shared CoreBundle for Keycloak JWT signature, issuer, audience, expiry, tenant and scope validation.

The service exposes an authenticated ingestion/query API and a RabbitMQ consumer. The consumer subscribes to existing durable domain-event exchanges, normalizes producer envelopes, and appends tenant-scoped events. Delivery is at-least-once; eventId provides tenant-scoped idempotency.

## API

Base URL (API Gateway): http://localhost:8088
Direct local URL: http://localhost:8101

| Method | Path | Scope | Description |
|---|---|---|---|
| GET | /health, /health/live | public | Liveness |
| GET | /health/ready | public | PostgreSQL readiness |
| POST | /api/v1/audit/events | audit:write | Append an audit event (idempotent by tenant + eventId) |
| GET | /api/v1/audit/events | audit:read | Search tenant events |
| GET | /api/v1/audit/events/{eventId} | audit:read | Read one tenant event |

### Ingest example

```http
POST /api/v1/audit/events
Authorization: Bearer <Keycloak access token>
Content-Type: application/json
X-Correlation-ID: order-journey-01
```

```json
{
  "eventId": "order:ord-1001:created:v1",
  "eventType": "order.created",
  "actorType": "user",
  "actorId": "user-123",
  "resourceType": "order",
  "resourceId": "ord-1001",
  "occurredAt": "2026-11-14T06:30:00Z",
  "correlationId": "order-journey-01",
  "payload": { "status": "created", "source": "web" }
}
```

New event: **201** with `created=true`. A retry with the same eventId in the same tenant returns **200** with `created=false`. Conflicting tenant IDs cannot read or deduplicate another tenant's event.

### Search

```http
GET /api/v1/audit/events?eventType=order.created&resourceType=order&resourceId=ord-1001&page=1&limit=25
Authorization: Bearer <Keycloak access token>
```

Filters: `eventType`, `actorId`, `resourceType`, `resourceId`, `from`, `to`, `page`, `limit` (1–100). Results are ordered newest first.

## RabbitMQ integration

The `audit-consumer` process binds a durable queue to existing producer exchanges: `autoflow.events`, `autoflow.order.events`, `autoflow.payment.events`, `autoflow.contract.events`, `autoflow.fulfillment.events`, `autoflow.crm.events`, `autoflow.catalog.events`, `autoflow.inventory.events`, `autoflow.vehicle-reservation.events`, `autoflow.document.events`, and `autoflow.configuration`.

Messages are normalized from `eventId/eventType/tenantId` (snake_case variants are accepted). Tenant ID can also be supplied in the AMQP `tenant_id` header. Events without a valid tenant or event identity are dead-lettered rather than stored under a guessed tenant. Transient persistence failures are retried up to five attempts through a 30-second retry queue, then routed to `audit.events.failed`. The consumer acknowledges a source message only after the audit insert or a confirmed retry/dead-letter publish.

Start the API and consumer:

```bash
docker compose --env-file .env.example up -d --build audit-service audit-consumer
docker compose --env-file .env.example logs -f audit-consumer
```

The consumer provides at-least-once delivery. The database uniqueness key `(tenant_id,event_id)` makes redelivery idempotent.

## Security model

- `audit:write` is for trusted producer service accounts; `audit:read` is for authorized users/services.
- The tenant comes only from the verified principal. Request payloads cannot choose or override a tenant.
- RLS is forced on `audit_events`; each repository operation sets the tenant in a transaction-local PostgreSQL setting.
- The database role has SELECT/INSERT only on audit events. UPDATE/DELETE are denied and a trigger rejects mutations.
- Payloads are limited to 16 KiB; common secret, credential, financial identifier and contact fields are redacted recursively.
- Never place access tokens, credentials, payment data or unnecessary customer data in audit payloads.

## Database and local run

The schema is mounted into the PostgreSQL initialization sequence. For an existing local volume, apply it once as the database administrator:

```bash
docker compose --env-file .env.example exec -T postgres psql -U autoflow -d autoflow < apps/audit-service/migrations/001_audit_schema.sql
```

Then build and start:

```bash
docker compose --env-file .env.example up -d --build audit-service
docker compose --env-file .env.example ps audit-service
curl -i http://localhost:8101/health/ready
```

Tests:

```bash
docker compose --env-file .env.example exec audit-service composer test
```

See [API reference](docs/API.md) for endpoint usage, parameters, scopes, examples and response codes. The machine-readable [OpenAPI contract](docs/openapi.yaml) is the per-service Swagger source; the aggregate contract is [docs/api/openapi.json](../../docs/api/openapi.json).
