# Order Management Service — API Reference

**Base URL:** http://localhost:8085 (gateway: http://localhost:8088)  
**Authentication:** Keycloak JWT, audience `autoflow-order`, verified tenant claim and endpoint roles. Order creation additionally requires `customers:read`, `offers:read`, `pricing:quote`, `catalog:read` and audience `autoflow-customer`.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/v1/orders` | orders:write + upstream read permissions | Idempotency-Key required. JSON required: offerId, quoteId, vehicleId, customerId (UUID). Service resolves immutable snapshots itself. | 201, 400, 403, 409, 422, 503 |
| GET | `/api/v1/orders` | orders:read | limit (1..100, default 20), offset (default 0). | 200 |
| GET | `/api/v1/orders/{orderId}` | orders:read | orderId path. | 200, 404 |
| GET | `/api/v1/orders/{orderId}/history` | orders:read | orderId path; append-only lifecycle history. | 200, 404 |
| POST | `/api/v1/orders/{orderId}/submit` | orders:write | orderId; Idempotency-Key required; no body. | 200, 409 |
| POST | `/api/v1/orders/{orderId}/review` | orders:manage | orderId; Idempotency-Key required; no body. | 200, 409 |
| POST | `/api/v1/orders/{orderId}/accept` | orders:manage | orderId; Idempotency-Key required; no body. | 200, 409 |
| POST | `/api/v1/orders/{orderId}/reject` | orders:manage | orderId; Idempotency-Key required. JSON required: reason (1..500 chars). | 200, 409 |
| POST | `/api/v1/orders/{orderId}/cancel` | orders:write | orderId; Idempotency-Key required; no body. | 200, 409 |
| POST | `/api/v1/orders/{orderId}/contract-pending` | orders:manage | orderId; Idempotency-Key required; no body. | 200 |
| POST | `/api/v1/orders/{orderId}/complete` | orders:manage | orderId; Idempotency-Key required; no body. | 200 |

Idempotency keys are scoped to tenant and authenticated actor. Replaying same key+command returns original response; same key for a different command returns 409. Invalid lifecycle transitions return 409. A 503 during order creation means an upstream service was unavailable and no order was created.

## Health
- `GET /health/ready` — readiness.
