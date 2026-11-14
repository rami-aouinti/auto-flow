# Payment Service — API Reference

**Direct local URL:** http://localhost:8086  
**Authentication:** Keycloak bearer JWT and tenant context; operation scopes: `payments:read`, `payments:write`, `payments:capture`, `payments:refund`. Stripe webhook is public at JWT layer but requires Stripe signature verification.

## HTTP endpoints

| Method | Path | Permission / access | Purpose | Arguments and request body | Documented / expected responses |
|---|---|---|---|---|---|
| POST | /api/v1/payments | payments:write | Create payment intent for an order; amount and currency are resolved from Order's quote snapshot. | Idempotency-Key required. JSON: orderId only. | 201, 400, 401, 403, 409, 422, 503 |
| GET | /api/v1/payments/{paymentId} | payments:read | Read payment. | paymentId (path). | 200, 401, 403, 404 |
| POST | /api/v1/payments/{paymentId}/capture | payments:capture | Capture authorized payment. | paymentId; Idempotency-Key required; no body documented. | 200, 401, 403, 404, 409, 422 |
| POST | /api/v1/payments/{paymentId}/cancel | payments:write | Cancel payment. | paymentId; Idempotency-Key required; no body documented. | 200, 401, 403, 404, 409, 422 |
| POST | /api/v1/payments/{paymentId}/refund | payments:refund | Refund payment. | paymentId; Idempotency-Key required. Amounts are integer minor units; exact optional refund body not specified in README. | 200, 400, 401, 403, 404, 409, 422 |
| POST | /api/v1/payments/webhooks/stripe | Stripe-Signature | Receive Stripe webhook event; validate signature using configured webhook secret. | Stripe-Signature header required; raw Stripe event payload. | 200, 400, 409 |

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
