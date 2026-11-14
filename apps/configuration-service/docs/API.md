# Configuration Service API

Base URL: `http://localhost:8104`. All business endpoints require a verified tenant and actor identity injected by the trusted API gateway. Required headers: `X-Autoflow-Auth-Verified: true`, `X-Tenant-Id`, `X-Actor-Id`. For local-only testing set `AUTH_MODE=development` and send the tenant/actor headers. Never expose this development mode publicly.

All responses are JSON; errors use RFC 7807-style `application/problem+json`. Tenant isolation is enforced in every query. Secrets and credentials must never be stored here.

## Endpoints

| Method | Path | Request | Success | Errors |
|---|---|---|---|---|
| GET | /health, /health/live | none | 200 status | — |
| GET | /health/ready | none | 200 | 503 database unavailable |
| GET | /api/configurations?namespace={namespace} | optional namespace | 200 {data:[...]} | 400 |
| GET | /api/configurations/{namespace}/{key} | none | 200 configuration + ETag | 401, 404, 503 |
| PUT | /api/configurations/{namespace}/{key} | {value, description?, expectedVersion?, reason?} | 201 created / 200 updated + ETag | 400, 401, 412, 422, 503 |
| GET | /api/configurations/{namespace}/{key}/versions | none | 200 {data:[versions]} | 401, 503 |
| POST | /api/configurations/{namespace}/{key}/rollback | {version:number} | 200 new version | 401, 404, 422, 503 |
| GET | /api/feature-flags | none | 200 {data:[flags]} | 401, 503 |
| GET | /api/feature-flags/{key} | none | 200 flag | 401, 404, 503 |
| PUT | /api/feature-flags/{key} | {enabled:boolean, rolloutPercentage?:0..100, tenantAllowlist?:string[], description?, expectedVersion?} | 201 / 200 + ETag | 401, 412, 422, 503 |
| GET | /api/feature-flags/{key}/evaluate | none | 200 {key,enabled,reason,version} | 401, 404, 503 |
| GET | /api/audit-events?limit={1..100} | optional limit | 200 {data:[events]} | 401, 503 |

### Configuration write example

```http
PUT /api/configurations/checkout/default_currency
Content-Type: application/json
X-Tenant-Id: tenant-demo
X-Actor-Id: demo-user
X-Autoflow-Auth-Verified: true

{"value":"EUR","description":"Storefront currency","expectedVersion":0,"reason":"Initial setup"}
```

A first write creates version 1. Subsequent writes increment the version. Optimistic concurrency accepts the JSON `expectedVersion` field or the standard HTTP `If-Match: \"2\"` header; a mismatch returns 412. `If-Match: *` requires the resource to exist. Version history is immutable. Rollback creates a new version rather than rewriting history.

### Feature flag example

```json
{"enabled":true,"rolloutPercentage":25,"tenantAllowlist":["tenant-demo"],"description":"Gradual rollout"}
```

Evaluation is deterministic for a tenant and flag key. The allowlist overrides percentage rollout; a disabled flag always evaluates false. This implementation intentionally targets tenant-level rollout, not individual users.

### Error shape

```json
{"type":"about:blank","title":"Precondition Failed","status":412,"detail":"Configuration version does not match expectedVersion."}
```

## Events and cache

Writes append audit and outbox records in the same PostgreSQL transaction. Event types: `ConfigurationUpdated`, `FeatureFlagChanged`. The `configuration-outbox-publisher` worker publishes events to the durable `autoflow.configuration` topic exchange and `configuration.events` queue. Failed deliveries are retried with exponential backoff capped at five minutes. Delivery is at-least-once; downstream consumers must deduplicate by `eventId`/AMQP `message_id`. Redis invalidation is best-effort and never makes a successful database write fail.
