# Audit Service — HTTP API reference

This document is the implementation-aligned reference for the Audit Service. The machine-readable contract is [`openapi.yaml`](openapi.yaml); the aggregate AutoFlow API contract is [`../../../docs/api/openapi.json`](../../../docs/api/openapi.json).

## Base URLs

| Access | Base URL |
|---|---|
| API Gateway | `http://localhost:8088` |
| Direct service | `http://localhost:8101` |

All routes below use JSON over HTTP. Protected routes require `Authorization: Bearer <Keycloak access token>`.

## Authentication and tenant isolation

| Route | Scope | Tenant |
|---|---|---|
| `GET /health`, `GET /health/live`, `GET /health/ready` | Public | None |
| `POST /api/v1/audit/events` | `audit:write` | Required `tenant_id` JWT claim |
| `GET /api/v1/audit/events` | `audit:read` | Required `tenant_id` JWT claim |
| `GET /api/v1/audit/events/{eventId}` | `audit:read` | Required `tenant_id` JWT claim |

The tenant is derived exclusively from the verified principal. A body field, query parameter or custom header cannot override it. PostgreSQL row-level security additionally isolates tenant reads.

## Endpoint catalogue

### 1. Health checks

- `GET /health` and `GET /health/live`: public liveness probes; `200` means the HTTP process is alive, not that PostgreSQL is available.
- `GET /health/ready`: public readiness probe; `200` when PostgreSQL is reachable, `503` otherwise.

### 2. Append an audit event

`POST /api/v1/audit/events` — requires `audit:write`.

Headers:
- `Authorization: Bearer <token>`
- `Content-Type: application/json`
- `X-Correlation-ID: <id>` (optional fallback if body `correlationId` is omitted)

Request fields:

| Field | Type | Required | Constraints / behavior |
|---|---|---:|---|
| `eventId` | string | Yes | 3–128 chars; pattern `^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$`; idempotency key per tenant |
| `eventType` | string | Yes | 1–120 chars |
| `actorType` | string | Yes | `user`, `service`, or `system` |
| `actorId` | string | No | Max 255 chars; defaults to JWT subject |
| `resourceType` | string | Yes | 1–80 chars |
| `resourceId` | string | Yes | 1–160 chars |
| `occurredAt` | string | Yes | ISO-8601 date-time; normalized to UTC |
| `correlationId` | string | No | Max 128 chars; otherwise `X-Correlation-ID` is used |
| `payload` | object/array | No | Defaults to empty object; serialized size max 16 KiB; sensitive fields recursively redacted |

Example:

```bash
curl -i -X POST http://localhost:8088/api/v1/audit/events \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: checkout-journey-42" \
  -d '{
    "eventId": "order:ord-1001:created:v1",
    "eventType": "order.created",
    "actorType": "user",
    "actorId": "user-123",
    "resourceType": "order",
    "resourceId": "ord-1001",
    "occurredAt": "2026-11-14T06:30:00Z",
    "payload": {"status": "created", "source": "web"}
  }'
```

Responses:

| Status | Meaning | Body |
|---|---|---|
| `201 Created` | New event inserted | `{"created":true,"eventId":"...","eventHash":"<sha256>"}` |
| `200 OK` | Same tenant + eventId already exists | `{"created":false,"eventId":"...","eventHash":"<sha256>"}` |
| `400` | Invalid JSON | Problem Details |
| `401` | Missing/invalid token | Problem Details |
| `403` | Missing `audit:write` or `tenant_id` | Problem Details |
| `413` | Payload exceeds 16 KiB | Problem Details |
| `422` | Required field, format, enum or length validation failed | Problem Details |
| `503` | Storage unavailable | Problem Details; retry with the same eventId |

The service accepts no tenant identifier from the caller. A retry using the same eventId is safe within the same tenant.

### 3. Search audit events

`GET /api/v1/audit/events` — requires `audit:read`.

| Query parameter | Type | Default / constraints |
|---|---|---|
| `eventType` | string | Exact match |
| `actorId` | string | Exact match |
| `resourceType` | string | Exact match |
| `resourceId` | string | Exact match |
| `from` | date-time | Inclusive lower bound |
| `to` | date-time | Inclusive upper bound |
| `page` | integer | Default 1; minimum 1 |
| `limit` | integer | Default 25; range 1–100 |

Example:

```bash
curl -G http://localhost:8088/api/v1/audit/events \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  --data-urlencode "eventType=order.created" \
  --data-urlencode "resourceType=order" \
  --data-urlencode "resourceId=ord-1001" \
  --data-urlencode "page=1" \
  --data-urlencode "limit=25"
```

Success response (`200`):

```json
{
  "data": [{
    "event_id": "order:ord-1001:created:v1",
    "event_type": "order.created",
    "actor_type": "user",
    "actor_id": "user-123",
    "resource_type": "order",
    "resource_id": "ord-1001",
    "occurred_at": "2026-11-14T06:30:00+00:00",
    "correlation_id": "checkout-journey-42",
    "payload": {"status": "created"},
    "previous_hash": "<sha256>",
    "event_hash": "<sha256>",
    "created_at": "2026-11-14T06:30:00+00:00"
  }],
  "meta": {"page": 1, "limit": 25, "total": 1, "pages": 1}
}
```

Results are tenant-scoped and sorted by `occurred_at DESC, event_id DESC`. Response event fields use database snake_case names.

| Status | Meaning |
|---|---|
| `200` | Search completed |
| `400` | Invalid page or limit |
| `401` | Missing/invalid token |
| `403` | Missing `audit:read` or tenant |
| `503` | Storage unavailable |

### 4. Read one event

`GET /api/v1/audit/events/{eventId}` — requires `audit:read`.

Path parameter: `eventId` (string, required).

Example:

```bash
curl -i "http://localhost:8088/api/v1/audit/events/order%3Aord-1001%3Acreated%3Av1" \
  -H "Authorization: Bearer $ACCESS_TOKEN"
```

Responses:
- `200`: `{"data": { ...audit event fields... }}`
- `401`: missing/invalid token.
- `403`: missing scope or tenant claim.
- `404`: event does not exist in the authenticated tenant; another tenant's event is not disclosed.
- `503`: storage lookup failed.

## Error format

Application errors use `application/problem+json`:

```json
{"type":"about:blank","title":"Validation failed","status":422,"detail":"eventId has an invalid format."}
```

Clients should branch on HTTP status and validation rules, not human-readable detail text.

## Integrity and data handling

- Append-only storage, mutation-blocking database trigger and restricted database permissions.
- Per-tenant SHA-256 chain links each event to the previous event hash.
- Idempotency key is `(tenant_id, event_id)`.
- Payloads are limited to 16 KiB and recursively redacted.
- Never include tokens, passwords, payment credentials or unnecessary personal data.
- RabbitMQ domain-event ingestion is an asynchronous worker, not an HTTP endpoint; it uses durable queues, bounded retry and a dead-letter queue.

## Swagger / OpenAPI

- Per-service OpenAPI 3.1: [`openapi.yaml`](openapi.yaml).
- Central AutoFlow OpenAPI JSON: [`docs/api/openapi.json`](../../../docs/api/openapi.json).
- Keep both contracts aligned with controller routes, scopes, validation and response bodies.
