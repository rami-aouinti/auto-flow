# Offer Service — API Reference

**Direct local URL:** http://localhost:8081  
**Authentication:** Verified Keycloak bearer token and tenant context. Reads require `offers:read`; create and lifecycle transitions require `offers:quote`. Operations are restricted to the authenticated offer creator in the current tenant.

## HTTP endpoints

| Method | Path | Permission / access | Purpose | Arguments and request body | Documented / expected responses |
|---|---|---|---|---|---|
| POST | /api/offers/{vehicleId} | offers:quote | Create an offer; calls Pricing and stores an immutable pricing snapshot. | vehicleId (path). Body: vehiclePrice, downPayment, residualValue (decimal strings with 2 fractional digits), currency (3-letter code), durationMonths (integer), annualInterestRateBasisPoints (integer). | 201/200 (service response), 400, 401, 403, 404, 409, 422, 503 |
| GET | /api/offers | offers:read | List offers created by the authenticated subject; returns items, total, limit, offset, newest first. | status? (draft\|published\|accepted\|rejected\|expired\|withdrawn), search? (max 100 chars), limit? (1..100, default 20), offset? (default 0). | 200, 400, 401, 403 |
| GET | /api/offers/{offerId} | offers:read | Retrieve one offer owned by caller. | offerId (path). | 200, 401, 403, 404 |
| GET | /api/offers/{offerId}/history | offers:read | Read append-only lifecycle audit events. | offerId (path). | 200, 401, 403, 404 |
| POST | /api/offers/{offerId}/publish | offers:quote | Publish a draft before expiry. | offerId (path); no request body documented. | 200, 401, 403, 404, 409 |
| POST | /api/offers/{offerId}/accept | offers:quote | Accept a published offer before expiry. | offerId (path); no request body documented. | 200, 401, 403, 404, 409 |
| POST | /api/offers/{offerId}/reject | offers:quote | Reject a published offer before expiry. | offerId (path); no request body documented. | 200, 401, 403, 404, 409 |
| POST | /api/offers/{vehicleId}/quotes | offers:quote | Legacy quote-only compatibility endpoint; does not create an Offer aggregate. | vehicleId (path); same lease terms as offer creation. | 200, 400, 401, 403, 422, 503 |

## Health and operations

- GET /health — liveness; no JWT.
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
