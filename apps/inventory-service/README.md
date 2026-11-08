# AutoFlow Inventory Service

Inventory is the source of truth for physical vehicle units. Vehicle Catalog owns model/variant specifications; Inventory owns physical VIN-bearing units, their location, condition, availability status and stock movement history; Vehicle Reservation owns temporary holds and reservation lifecycle.

## Responsibilities
- Register one physical unit per VIN and catalogue variant.
- Track dealer/location assignment, country, condition, mileage and first registration date.
- Expose tenant-scoped stock lists and availability counts by variant.
- Apply explicit, versioned stock-state transitions with optimistic concurrency.
- Keep append-only movement history, audit events and transactional Outbox events.
- Enforce tenant isolation with PostgreSQL RLS and a dedicated non-superuser role.

Inventory does not create reservations or decide commercial eligibility. The Reservation service remains responsible for locking a specific physical unit during a customer journey. The current first slice exposes the Inventory API and durable events; connecting Reservation to Inventory is a subsequent integration step.

## API
All endpoints require a Keycloak access token with audience `autoflow-inventory`, tenant claim and operation-specific permission.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| POST | /api/v1/inventory/units | inventory:write | Register a physical vehicle; Idempotency-Key required |
| GET | /api/v1/inventory/units | inventory:read | Filter by variant, status and dealer |
| GET | /api/v1/inventory/availability?vehicleVariantId={uuid} | inventory:read | Availability counts by status |
| GET | /api/v1/inventory/units/{id} | inventory:read | Read one physical unit |
| POST | /api/v1/inventory/units/{id}/status | inventory:write | Versioned status transition; Idempotency-Key and If-Match required |
| GET | /api/v1/inventory/units/{id}/history | inventory:read | Read stock movement history |

Supported stock states: available, blocked, in_transit, delivered, sold, maintenance, quarantined. VIN is normalized to uppercase and unique per tenant. Mutations write the stock row, movement, audit and Outbox event in one PostgreSQL transaction.

## Local
- Direct API: http://localhost:8093
- Gateway: http://localhost:8088/api/v1/inventory
- Readiness: http://localhost:8093/health/ready

```bash
docker compose --env-file .env.example run --rm postgres-bootstrap
docker compose --env-file .env.example up -d --build inventory-service api-gateway
docker compose --env-file .env.example ps inventory-service api-gateway
curl -i http://localhost:8093/health/ready
```

The local Keycloak realm must contain the `autoflow-inventory` audience and `inventory:read` / `inventory:write` permissions. Existing realm imports are not merged automatically; update an existing realm deliberately.
