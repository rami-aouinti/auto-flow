# Offer Service — API Reference

**Base URL:** http://localhost:8081  
**Authentication:** Bearer JWT; offers:read, offers:quote

## Endpoints and arguments

| Method | Path | Arguments / body | Possible responses |
|---|---|---|---|
| POST | `/api/offers/{vehicleId}` |  body: vehiclePrice, downPayment, residualValue, currency, durationMonths, annualInterestRateBasisPoints | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| GET | `/api/offers` |  query: status, search, limit (1..100), offset | 200, 400, 401, 403, 404, 500 |
| GET | `/api/offers/{offerId}` | Path parameters are shown in braces; no body documented. | 200, 400, 401, 403, 404, 500 |
| GET | `/api/offers/{offerId}/history` | Path parameters are shown in braces; no body documented. | 200, 400, 401, 403, 404, 500 |
| POST | `/api/offers/{offerId}/publish` | Path parameters are shown in braces; no body documented. | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| POST | `/api/offers/{offerId}/accept` | Path parameters are shown in braces; no body documented. | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| POST | `/api/offers/{offerId}/reject` | Path parameters are shown in braces; no body documented. | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |
| POST | `/api/offers/{vehicleId}/quotes` |  same lease-term body | 200/201, 400, 401, 403, 404, 409, 412, 422, 500, 503 |

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

- `GET /health` — liveness; no JWT.
- `GET /health/ready` — readiness where CoreBundle readiness is enabled; no JWT.
