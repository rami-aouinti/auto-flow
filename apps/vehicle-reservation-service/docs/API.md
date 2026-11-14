# Vehicle Reservation Service — API Reference

**Direct local URL:** http://localhost:8092  
**Authentication:** Keycloak JWT; audience `autoflow-vehicle-reservation`, tenant claim and endpoint-specific `reservations:*` permission.

## HTTP endpoints

| Method | Path | Permission / access | Purpose | Arguments and request body | Documented / expected responses |
|---|---|---|---|---|---|
| GET | /api/v1/reservations | reservations:read | List tenant reservations. | status?, limit?, offset?. | 200, 400, 401, 403 |
| POST | /api/v1/reservations | reservations:write | Hold a physical stock unit. | Idempotency-Key required. JSON required: vehicleUnitId, vehicleVariantId, orderId (UUIDs), expiresAt (date-time). | 201, 400, 401, 403, 409, 412, 422 |
| GET | /api/v1/reservations/{id} | reservations:read | Read reservation. | id (path). | 200, 401, 403, 404 |
| GET | /api/v1/reservations/{id}/history | reservations:read | Read audit history. | id (path). | 200, 401, 403, 404 |
| POST | /api/v1/reservations/{id}/confirm | reservations:write | Confirm hold before expiry. | id; Idempotency-Key and current If-Match ETag required; no body documented. | 200, 401, 403, 404, 409, 412, 422 |
| POST | /api/v1/reservations/{id}/release | reservations:write | Release held or confirmed unit. | id; Idempotency-Key and current If-Match ETag required; no body documented. | 200, 401, 403, 404, 409, 412, 422 |
| POST | /api/v1/reservations/{id}/fulfill | reservations:manage | Mark confirmed reservation fulfilled. | id; Idempotency-Key and current If-Match ETag required; no body documented. | 200, 401, 403, 404, 409, 412, 422 |

## Health and operations

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
