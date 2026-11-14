# Vehicle Reservation Service — API Reference

**Base URL:** http://localhost:8092 (gateway: http://localhost:8088/api/v1/reservations)  
**Authentication:** Keycloak JWT, audience `autoflow-vehicle-reservation`, tenant claim and operation-specific `reservations:*` permission.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| GET | `/api/v1/reservations` | reservations:read | status?, limit?, offset?. | 200, 400, 401, 403 |
| POST | `/api/v1/reservations` | reservations:write | Idempotency-Key required. JSON: vehicleUnitId, vehicleVariantId, orderId, expiresAt. | 201, 400, 401, 403, 409, 412, 422 |
| GET | `/api/v1/reservations/{id}` | reservations:read | id path. | 200, 401, 403, 404 |
| GET | `/api/v1/reservations/{id}/history` | reservations:read | id path; audit history. | 200, 401, 403, 404 |
| POST | `/api/v1/reservations/{id}/confirm` | reservations:write | id; Idempotency-Key + current If-Match ETag required; no body documented. | 200, 401, 403, 404, 409, 412, 422 |
| POST | `/api/v1/reservations/{id}/release` | reservations:write | id; Idempotency-Key + current If-Match ETag required; no body documented. | 200, 401, 403, 404, 409, 412, 422 |
| POST | `/api/v1/reservations/{id}/fulfill` | reservations:manage | id; Idempotency-Key + current If-Match ETag required; no body documented. | 200, 401, 403, 404, 409, 412, 422 |

Stale version returns 412; conflicting transition/stock allocation returns 409; invalid input returns 400 or 422 using Problem Details.

## Confirmed create request
```json
{
  "vehicleUnitId": "11111111-1111-4111-8111-111111111111",
  "vehicleVariantId": "22222222-2222-4222-8222-222222222222",
  "orderId": "33333333-3333-4333-8333-333333333333",
  "expiresAt": "2026-11-08T12:00:00Z"
}
```

## Health
- `GET /health/ready` — readiness.
