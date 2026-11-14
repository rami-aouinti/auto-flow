# Pricing Service — API Reference

**Direct local URL:** http://localhost:8082  
**Authentication:** Keycloak JWT; `autoflow-pricing` audience, tenant context and `pricing:quote`. Read/delete own quotes by default; `pricing:quote:read:any` and `pricing:quote:delete:any` widen access only within authorized tenant.

## HTTP endpoints

| Method | Path | Permission / access | Purpose | Arguments and request body | Documented / expected responses |
|---|---|---|---|---|---|
| POST | /api/pricing/quotes | pricing:quote | Calculate and persist an immutable quote snapshot. | JSON required: vehicleId, vehiclePrice, downPayment, residualValue, currency, durationMonths, annualInterestRateBasisPoints. Money is decimal string with 2 decimals; demo policy duration 12..72 months and rate 0..2000 basis points. | 200, 400, 401, 403, 422 |
| GET | /api/pricing/quotes | pricing:quote | List caller's quotes. | limit?, offset?. | 200, 400, 401, 403 |
| GET | /api/pricing/quotes/{quoteId} | pricing:quote | Retrieve a quote snapshot. | quoteId (path). | 200, 401, 403, 404, 410 |
| DELETE | /api/pricing/quotes/{quoteId} | pricing:quote | Soft-delete a quote. | quoteId (path). | 204, 401, 403, 404 |
| GET | /api/pricing/{vehicleId} | Deprecated compatibility route | Static demo compatibility endpoint; deprecated. | vehicleId (path). | 200, 404 |

## Health and operations

- GET /health — liveness; no JWT.
- GET /ready — PostgreSQL readiness; no JWT.

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
