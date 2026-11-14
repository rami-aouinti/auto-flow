# Order Management Service — API Reference

**Direct local URL:** http://localhost:8085  
**Authentication:** Keycloak bearer JWT; audience `autoflow-order`, verified tenant claim and operation-specific roles. Creating an order also requires customers:read, offers:read, pricing:quote, catalog:read and the `autoflow-customer` audience.

## HTTP endpoints

| Method | Path | Permission / access | Purpose | Arguments and request body | Documented / expected responses |
|---|---|---|---|---|---|
| POST | /api/v1/orders | orders:write + customers:read + offers:read + pricing:quote + catalog:read | Create draft order after resolving and validating customer, offer, quote and vehicle snapshots. | Idempotency-Key required. JSON required: offerId, quoteId, vehicleId, customerId (UUID). Client snapshots are not trusted. | 201, 400, 403, 409, 422, 503 |
| GET | /api/v1/orders | orders:read | List tenant orders. | limit? (1..100, default 20), offset? (default 0). | 200 |
| GET | /api/v1/orders/{orderId} | orders:read | Retrieve order and immutable snapshots. | orderId (UUID path). | 200, 404 |
| GET | /api/v1/orders/{orderId}/history | orders:read | Retrieve append-only lifecycle audit history. | orderId (UUID path). | 200, 404 |
| POST | /api/v1/orders/{orderId}/submit | orders:write | Submit draft order. | orderId; Idempotency-Key required; no body documented. | 200, 409 |
| POST | /api/v1/orders/{orderId}/review | orders:manage | Start manual review. | orderId; Idempotency-Key required; no body documented. | 200, 409 |
| POST | /api/v1/orders/{orderId}/accept | orders:manage | Accept order. | orderId; Idempotency-Key required; no body documented. | 200, 409 |
| POST | /api/v1/orders/{orderId}/reject | orders:manage | Reject order with reason. | orderId; Idempotency-Key required. JSON: reason (1..500 chars). | 200, 409 |
| POST | /api/v1/orders/{orderId}/cancel | orders:write | Cancel where allowed. | orderId; Idempotency-Key required; no body documented. | 200, 409 |
| POST | /api/v1/orders/{orderId}/contract-pending | orders:manage | Mark order as awaiting contract preparation. | orderId; Idempotency-Key required; no body documented. | 200 |
| POST | /api/v1/orders/{orderId}/complete | orders:manage | Complete fulfilment. | orderId; Idempotency-Key required; no body documented. | 200 |

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
