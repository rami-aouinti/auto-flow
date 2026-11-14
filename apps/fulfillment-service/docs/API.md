# Fulfillment Service — API Reference

**Base URL:** http://localhost:8091 (gateway: http://localhost:8088/api/v1/fulfillments)  
**Authentication:** Tenant-scoped CoreBundle JWT. Every route requires the permission shown.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| GET | `/api/v1/fulfillments` | fulfillment:read | List/filter; exact query names are service-defined and not enumerated in README. | 200, 400, 401, 403 |
| GET | `/api/v1/fulfillments/{id}` | fulfillment:read | id path. | 200, 401, 403, 404 |
| GET | `/api/v1/fulfillments/{id}/history` | fulfillment:read | id path; audit history. | 200, 401, 403, 404 |
| POST | `/api/v1/fulfillments/{id}/preparation/start` | fulfillment:prepare | id path; Idempotency-Key and If-Match: "version" required. | 200, 400, 401, 403, 404, 409, 412, 422 |
| POST | `/api/v1/fulfillments/{id}/preparation/complete` | fulfillment:prepare | id path; Idempotency-Key and If-Match required. | 200, 400, 401, 403, 404, 409, 412, 422 |
| POST | `/api/v1/fulfillments/{id}/schedule` | fulfillment:schedule | id path; Idempotency-Key and If-Match required; schedule DTO details are not in README. | 200, 400, 401, 403, 404, 409, 412, 422 |
| POST | `/api/v1/fulfillments/{id}/dispatch` | fulfillment:dispatch | id path; Idempotency-Key and If-Match required. | 200, 400, 401, 403, 404, 409, 412, 422 |
| POST | `/api/v1/fulfillments/{id}/delivery/confirm` | fulfillment:deliver | id path; Idempotency-Key and If-Match required. | 200, 400, 401, 403, 404, 409, 412, 422 |
| POST | `/api/v1/fulfillments/{id}/delivery/fail` | fulfillment:deliver | id path; Idempotency-Key and If-Match required. | 200, 400, 401, 403, 404, 409, 412, 422 |
| POST | `/api/v1/fulfillments/{id}/cancel` | fulfillment:cancel | id path; Idempotency-Key and If-Match required; cancel before dispatch. | 200, 400, 401, 403, 404, 409, 412, 422 |

State-changing commands lock the tenant-owned row, check expected version, update aggregate, write audit and Outbox, and store idempotent response in one transaction. Stale ETag returns 412; invalid transition returns 409. CORS allows If-Match and exposes ETag.

## Health
- `GET /health/ready` — readiness.
