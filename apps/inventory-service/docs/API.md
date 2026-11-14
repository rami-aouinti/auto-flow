# Inventory Service — API Reference

**Base URL:** http://localhost:8093 (gateway: http://localhost:8088/api/v1/inventory)  
**Authentication:** Keycloak JWT, audience `autoflow-inventory`, tenant claim and `inventory:read` / `inventory:write`.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/v1/inventory/units` | inventory:write | Idempotency-Key required. Register physical unit: vehicleVariantId, VIN, dealer/location, country, condition, mileage, first registration date. Exact DTO constraints are defined by implementation. | 201, 400, 401, 403, 409, 422 |
| GET | `/api/v1/inventory/units` | inventory:read | Filter by vehicleVariantId?, status?, dealerId?; pagination implementation-defined. | 200, 400, 401, 403 |
| GET | `/api/v1/inventory/availability?vehicleVariantId={uuid}` | inventory:read | Required query: vehicleVariantId. | 200, 400, 401, 403 |
| GET | `/api/v1/inventory/units/{id}` | inventory:read | id path. | 200, 401, 403, 404 |
| POST | `/api/v1/inventory/units/{id}/status` | inventory:write | id; Idempotency-Key and If-Match required. JSON: target status and optional reason. | 200, 400, 401, 403, 404, 409, 412, 422 |
| GET | `/api/v1/inventory/units/{id}/history` | inventory:read | id path; stock movement history. | 200, 401, 403, 404 |

Supported stock states: available, blocked, in_transit, delivered, sold, maintenance, quarantined. VIN is normalized uppercase and unique per tenant. Mutations atomically persist stock, movement, audit and Outbox event.

## Health
- `GET /health/ready` — readiness.
