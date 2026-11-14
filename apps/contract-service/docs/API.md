# Contract Service — API Reference

**Base URL:** http://localhost:8090  
**Authentication:** Bearer JWT; contracts:read/write/manage; create also orders:read

## Endpoints and arguments

| Method | Path | Arguments / body | Possible responses |
|---|---|---|---|
| POST | `/api/v1/contracts` |  Idempotency-Key; body: orderId, contractType | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| GET | `/api/v1/contracts` |  query: status | 200, 400, 401, 403, 404, 500 |
| GET | `/api/v1/contracts/{contractId}` | Path parameters are shown in braces; no body documented. | 200, 400, 401, 403, 404, 500 |
| GET | `/api/v1/contracts/{contractId}/history` | Path parameters are shown in braces; no body documented. | 200, 400, 401, 403, 404, 500 |
| POST | `/api/v1/contracts/{contractId}/prepare` |  Idempotency-Key | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| POST | `/api/v1/contracts/{contractId}/cancel` |  Idempotency-Key | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| POST | `/api/v1/contracts/{contractId}/document` | Path parameters are shown in braces; no body documented. | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| GET | `/api/v1/contracts/{contractId}/document` | Path parameters are shown in braces; no body documented. | 200, 400, 401, 403, 404, 500 |
| POST | `/api/v1/contracts/{contractId}/send-for-signature` | Path parameters are shown in braces; no body documented. | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |

## Common HTTP responses

- **2xx:** request succeeded; GET returns a resource/collection, POST creates or transitions a resource, DELETE may return 204.
- **400:** malformed JSON, invalid query parameters or malformed headers.
- **401:** missing/invalid JWT (except explicitly public provider webhooks).
- **403:** missing scope/role or tenant access.
- **404:** resource does not exist or is outside the caller's tenant/ownership boundary.
- **409:** invalid state transition, duplicate resource or idempotency-key conflict.
- **412:** stale ETag/If-Match version for optimistic concurrency.
- **422:** request is syntactically valid but violates domain validation.
- **500 / 503:** unexpected server failure or unavailable dependency; no internal SQL/stack trace should be exposed.

## Testing

Use the central Swagger UI at http://localhost:8096. Authenticate with the raw Keycloak access token when required. For commands requiring idempotency, send a unique `Idempotency-Key`; for versioned transitions copy the current `ETag` into `If-Match`.

## Contract maintenance

This reference documents the service-owned API surface currently described by the service README. If an argument or response schema is not explicitly described in the source documentation, it is not invented here; update this file and the OpenAPI catalog together when changing controllers.


## Health and operational endpoints

- `GET /health/ready` — readiness probe; no JWT.
