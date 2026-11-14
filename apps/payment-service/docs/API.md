# Payment Service — API Reference

**Base URL:** http://localhost:8086  
**Authentication:** Keycloak bearer JWT, tenant context and operation scopes: `payments:read`, `payments:write`, `payments:capture`, `payments:refund`. Stripe webhook is excluded from JWT and must validate its Stripe signature.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/v1/payments` | payments:write | Idempotency-Key required. JSON: orderId only. Amount/currency are resolved from the immutable Order quote snapshot. | 201, 400, 401, 403, 409, 422, 503 |
| GET | `/api/v1/payments/{paymentId}` | payments:read | paymentId path. | 200, 401, 403, 404 |
| POST | `/api/v1/payments/{paymentId}/capture` | payments:capture | paymentId; Idempotency-Key required; no body documented. | 200, 401, 403, 404, 409, 422 |
| POST | `/api/v1/payments/{paymentId}/cancel` | payments:write | paymentId; Idempotency-Key required; no body documented. | 200, 401, 403, 404, 409, 422 |
| POST | `/api/v1/payments/{paymentId}/refund` | payments:refund | paymentId; Idempotency-Key required. Amounts are integer minor units (e.g. EUR 1299 = €12.99); exact optional partial-refund body is not published in README. | 200, 400, 401, 403, 404, 409, 422 |
| POST | `/api/v1/payments/webhooks/stripe` | Stripe signature | Stripe-Signature header required; raw Stripe event payload. | 200, 400, 409 |

Stripe CLI local forwarding target: `localhost:8086/api/v1/payments/webhooks/stripe`. Configured webhook events: payment_intent.amount_capturable_updated, payment_intent.succeeded, payment_intent.payment_failed, payment_intent.canceled.

## Health
- `GET /health/ready` — readiness.
