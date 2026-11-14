# Vehicle Catalog Service — API Reference

**Direct local URL:** http://localhost:8084  
**Authentication:** Keycloak RS256 JWT; audience `autoflow-catalog`. Global catalogue (not tenant-owned). Reads require `catalog:read`; writes and publishing require `catalog:manage`.

## HTTP endpoints

| Method | Path | Permission / access | Purpose | Arguments and request body | Documented / expected responses |
|---|---|---|---|---|---|
| GET | /api/v1/manufacturers | catalog:read | List published manufacturers. | No query arguments documented. | 200, 400, 401, 403 |
| GET | /api/v1/vehicles | catalog:read | List published variants with filters and pagination. | q?, manufacturerId?, modelId?, generationId?, fuelType?, powertrain?, bodyStyle?, transmission?, minPowerKw?, maxPowerKw?, minSeats?, maxSeats?, limit (1..100)?, offset (0+)? | 200, 400, 401, 403 |
| GET | /api/v1/vehicles/{variantId} | catalog:read | Read published variant detail. | variantId (path). | 200, 401, 403, 404 |
| POST | /api/v1/manufacturers | catalog:manage | Create a draft manufacturer. | JSON manufacturer fields; exact DTO schema is maintained in service code. | 201, 400, 401, 403, 409, 422 |
| POST | /api/v1/models | catalog:manage | Create a draft model. | JSON model fields incl. manufacturer relationship; exact DTO schema in service code. | 201, 400, 401, 403, 422 |
| POST | /api/v1/generations | catalog:manage | Create a draft generation. | JSON generation fields incl. model relationship; exact DTO schema in service code. | 201, 400, 401, 403, 422 |
| POST | /api/v1/vehicles | catalog:manage | Create a draft technical variant. | JSON technical fields; measurements use explicit units in field names; exact DTO schema in service code. | 201, 400, 401, 403, 422 |
| POST | /api/v1/vehicles/{variantId}/publish | catalog:manage | Publish variant and non-archived ancestors. | variantId (path); no body documented. | 200, 401, 403, 404, 409 |

## Health and operations

- GET /health/live — liveness; no JWT.
- GET /health/ready — readiness; no JWT.

## Common response semantics

- `400`: malformed JSON, query string, or required header (when applicable).
- `401`: missing/invalid bearer token on protected routes.
- `403`: authenticated caller lacks the endpoint permission or tenant access.
- `404`: resource is unknown or intentionally hidden by tenant/ownership isolation.
- `409`: business lifecycle conflict or idempotency key reused for a different command.
- `412`: stale `If-Match` version for optimistic concurrency.
- `422`: syntactically valid request that fails domain validation.
- `503`: required upstream service/dependency unavailable.

Only status codes explicitly described in the service README or API contract are asserted in the endpoint table. Other statuses in the common section are general meanings and may not be emitted by every endpoint.

## Testing through Swagger UI

Open [http://localhost:8096](http://localhost:8096). The gateway base URL is `http://localhost:8088`; service routes are forwarded without stripping their API path. Use the raw Keycloak access token in **Authorize** for protected services. For idempotent commands, provide a unique `Idempotency-Key`. For versioned commands, read the current response `ETag` and send it unchanged as `If-Match`.

## Contract accuracy

Request fields are marked as DTO-defined where the service README does not publish the full DTO. The central OpenAPI contract provides strict schemas only for request bodies whose complete fields are confirmed by service documentation. Keep this file and `docs/api/openapi.json` synchronized when changing controllers, validators or response DTOs.
