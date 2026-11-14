# Inventory Service — API Reference

**Base URL:** http://localhost:8093  
**Authentication:** Bearer JWT; inventory:read/write

## Endpoints and arguments

| Method | Path | Arguments / body | Possible responses |
|---|---|---|---|
| POST | `/api/v1/inventory/units` |  Idempotency-Key; body: vehicleVariantId, VIN, dealer/location, country, condition, mileage, first registration date | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| GET | `/api/v1/inventory/units` |  query: vehicleVariantId, status, dealerId | 200, 400, 401, 403, 404, 500 |
| GET | `/api/v1/inventory/availability` |  query: vehicleVariantId (required) | 200, 400, 401, 403, 404, 500 |
| GET | `/api/v1/inventory/units/{id}` | Path parameters are shown in braces; no body documented. | 200, 400, 401, 403, 404, 500 |
| POST | `/api/v1/inventory/units/{id}/status` |  Idempotency-Key, If-Match; body: target status, optional reason | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| GET | `/api/v1/inventory/units/{id}/history` | Path parameters are shown in braces; no body documented. | 200, 400, 401, 403, 404, 500 |

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
