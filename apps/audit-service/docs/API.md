# Audit Service — HTTP API reference

This document is the detailed, implementation-aligned reference for the Audit Service. The machine-readable contract is [`docs/openapi.yaml`](openapi.yaml); the aggregate AutoFlow Swagger document is [`docs/api/openapi.json`](../../../docs/api/openapi.json).

## 1. Base URLs

| Access | Base URL |
|---|---|
| API Gateway | `http://localhost:8088` |
| Direct service (local development) | `http://localhost:8101` |

All paths below are relative to the selected base URL. The API is JSON over HTTP. Protected routes require a Keycloak access token in the `Authorization: Bearer <token>` header.

## 2. Authentication and authorization

| Operation | Required scope | Tenant |
|---|---|---|
| `GET /health` | Public | Not applicable |
| `GET /health/live` | Public | Not applicable |
| `GET /health/ready` | Public | Not applicable |
| `POST /api/v1/audit/events` | `audit:write` | Required `tenant_id` JWT claim |
| `GET /api/v1/audit/events` | `audit:read` | Required `tenant_id` JWT claim |
| `GET /api/v1/audit/events/{eventId}` | `audit:read` | Required `tenant_id` JWT claim |

The tenant is always taken from the verified token principal. A request body, query parameter, or custom header cannot select another tenant. Reads are additionally constrained by PostgreSQL row-level security (RLS).

## 3. Endpoint catalogue

### 3.1 Health checks

#### `GET /health` and `GET /health/live`

Public liveness probes. They indicate that the HTTP process is running; they do not guarantee database availability.

- `200 OK`: process is alive.

#### `GET /health/ready`

Public readiness probe.

- `200 OK`: PostgreSQL is reachable.
- `503 Service Unavailable`: the database readiness check failed.

### 3.2 Append an audit event

#### `POST /api/v1/audit/events`

Required scope: `audit:write`.

Headers:
- `Authorization: Bearer <access-token>`
- `Content-Type: application/json`
- `X-Correlation-ID: <optional-id>` (optional; body `correlationId` takes precedence when non-empty)

Request body:

| Field | Type | Required | Constraints / behavior |
|---|---|---:|---|
| `eventId` | string | Yes | 3–128 chars; pattern `^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$`; idempotency key within tenant |
| `eventType` | string | Yes | 1–120 chars |
| `actorType` | string | Yes | One of `user`, `service`, `system` |
| `actorId` | string | No | Max 255 chars; defaults to authenticated JWT subject |
| `resourceType` | string | Yes | 1–80 chars |
| `resourceId` | string | Yes | 1–160 chars |
| `occurredAt` | string | Yes | ISO-8601 date-time; normalized to UTC |
| `correlationId` | string | No | Max 128 chars; otherwise uses `X-Correlation-ID` |
| `payload` | object/array | No | Defaults to empty object; serialized size max 16 KiB; sensitive fields are recursively redacted |

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
| `201 Created` | Event inserted | `{"created":true,"eventId":"...","eventHash":"<sha256>"}` |
| `200 OK` | Same tenant + `eventId` already exists | `{"created":false,"eventId":"...","eventHash":"<sha256>"}` |
| `400 Bad Request` | Body is not valid JSON | Problem Details |
| `401 Unauthorized` | Missing/invalid bearer token | Problem Details |
| `403 Forbidden` | Missing `audit:write` or `tenant_id` claim | Problem Details |
| `413 Content Too Large` | Payload exceeds 16 KiB | Problem Details |
| `422 Unprocessable Entity` | Required field, format, enum, or length validation failed | Problem Details |
| `503 Service Unavailable` | Audit storage failed | Problem Details; retry with the same `eventId` |

A retry with the same `eventId` is safe within the same tenant. The database uniqueness key is tenant-scoped. The service does not accept a tenant identifier in this request.

### 3.3 Search audit events

#### `GET /api/v1/audit/events`

Required scope: `audit:read`.

Query parameters:

| Name | Type | Required | Default / constraints |
|---|---|---:|---|
| `eventType` | string | No | Exact match |
| `actorId` | string | No | Exact match |
| `resourceType` | string | No | Exact match |
| `resourceId` | string | No | Exact match |
| `from` | date-time | No | Inclusive lower bound for `occurred_at` |
| `to` | date-time | No | Inclusive upper bound for `occurred_at` |
| `page` | integer | No | Default 1; minimum 1 |
| `limit` | integer | No | Default 25; range 1–100 |

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

Success response (`200 OK`):

```json
{
  "data": [
    {
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
    }
  ],
  "meta": {"page": 1, "limit": 25, "total": 1, "pages": 1}
}
```

Results are tenant-scoped and ordered by `occurred_at DESC, event_id DESC`. Search response event fields use the database snake_case names shown above.

| Status | Meaning |
|---|---|
| `200` | Search completed |
| `400` | Invalid `page` or `limit` |
| `401` | Missing/invalid bearer token |
| `403` | Missing `audit:read` or `tenant_id` |
| `503` | Audit storage unavailable |

### 3.4 Read one event

#### `GET /api/v1/audit/events/{eventId}`

Required scope: `audit:read`.

Path parameter:
- `eventId` (string, required): exact event identifier.

Example:

```bash
curl -i "http://localhost:8088/api/v1/audit/events/order%3Aord-1001%3Acreated%3Av1" \
  -H "Authorization: Bearer $ACCESS_TOKEN"
```

Responses:
- `200 OK`: `{"data": { ...audit event fields... }}`
- `401 Unauthorized`: missing/invalid token.
- `403 Forbidden`: missing scope or tenant claim.
- `404 Not Found`: event does not exist in the authenticated tenant (events in other tenants are not disclosed).
- `503 Service Unavailable`: storage lookup failed.

## 4. Error response format

Application errors use `application/problem+json`:

```json
{
  "type": "about:blank",
  "title": "Validation failed",
  "status": 422,
  "detail": "eventId has an invalid format."
}
```

The `detail` field is safe diagnostic text. Clients should branch on the HTTP status and stable validation rules rather than parsing human-readable detail strings.

## 5. Event integrity and data handling

- Events are append-only; application database permissions and a mutation-blocking trigger prevent ordinary updates/deletes.
- A per-tenant SHA-256 chain links each event to the previous event hash.
- Duplicate detection is scoped to `(tenant_id, event_id)`.
- Payloads are limited to 16 KiB and recursively redacted before persistence.
- Never include bearer tokens, passwords, payment credentials, or unnecessary personal data in an audit payload.
- Domain events are also consumed asynchronously from configured RabbitMQ exchanges. That consumer uses a durable queue, bounded retry and a dead-letter queue; it is not an HTTP endpoint.

## 6. Swagger / OpenAPI

- Per-service contract: [`docs/openapi.yaml`](openapi.yaml), OpenAPI 3.1.
- Central API contract: [`docs/api/openapi.json`](../../../docs/api/openapi.json), served through the repository's Swagger UI setup.
- Keep both contracts in sync when changing controller routes, validation, scopes, or response payloads.
