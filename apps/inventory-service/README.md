# AutoFlow Inventory Service

Inventory is the source of truth for physical vehicle units. Vehicle Catalog owns model/variant specifications; Inventory owns physical VIN-bearing units, their location, condition, availability status and stock movement history; Vehicle Reservation owns temporary holds and reservation lifecycle.

## Responsibilities
- Register one physical unit per VIN and catalogue variant.
- Track dealer/location assignment, country, condition, mileage and first registration date.
- Expose tenant-scoped stock lists and availability counts by variant.
- Apply explicit, versioned stock-state transitions with optimistic concurrency.
- Keep append-only movement history, audit events and transactional Outbox events.
- Enforce tenant isolation with PostgreSQL RLS and a dedicated non-superuser role.

Inventory does not create reservations or decide commercial eligibility. The Reservation service remains responsible for locking a specific physical unit during a customer journey. Inventory emits durable stock events through its Outbox. The Reservation service still has its own stock projection today; consuming Inventory events and replacing its current stock registry with an Inventory-backed allocation flow remains an explicit integration task. Do not treat the two registries as already synchronized.

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

Supported stock states: available, blocked, in_transit, delivered, sold, maintenance, quarantined. VIN is normalized to uppercase and unique per tenant. Mutations write the stock row, movement, audit and Outbox event in one PostgreSQL transaction. Event types are inventory.unit.created.v1 and inventory.status.changed.v1. The publisher uses a dedicated database login that can only execute three narrowly scoped claim/ack/retry functions; the function owner is a separate no-login role. RabbitMQ messages are persistent and confirmed, and failed events use exponential retry with a terminal failure state after 12 attempts. Delivery is at-least-once: consumers must deduplicate using eventId.

## Local
- Direct API: http://localhost:8093
- Gateway: http://localhost:8088/api/v1/inventory
- Readiness: http://localhost:8093/health/ready

```bash
docker compose --env-file .env.example run --rm postgres-bootstrap
docker compose --env-file .env.example up -d --build inventory-service inventory-outbox-publisher api-gateway
docker compose --env-file .env.example ps inventory-service inventory-outbox-publisher api-gateway
curl -i http://localhost:8093/health/ready
```

The local Keycloak realm must contain the `autoflow-inventory` audience and `inventory:read` / `inventory:write` permissions. Existing realm imports are not merged automatically; update an existing realm deliberately.

## Outbox operations

The root Compose file contains both the HTTP service and its Outbox publisher:

```bash
docker compose --env-file .env.example up -d --build inventory-outbox-publisher
docker compose --env-file .env.example logs -f inventory-outbox-publisher
```

The worker publishes to the durable topic exchange and queue autoflow.inventory.events. Consumers should bind their own durable queues to the exchange using the event routing keys. The shared queue is a local diagnostic buffer, not a replacement for service-specific consumer queues.

## Current integration boundary

- Vehicle Catalog remains the owner of vehicle variants. Inventory stores the opaque variant ID and does not yet perform a synchronous or event-projection check that the variant exists and is published.
- Vehicle Reservation still allocates from its own vehicle_stock_units projection. The Inventory Outbox is ready for integration, but no Inventory-to-Reservation consumer or atomic cross-service allocation command is included yet. Until that work is implemented, do not seed or operate the two stock registries independently in a production workflow.
- The GitHub change has not been run against a local PostgreSQL/RabbitMQ stack in this session. Apply the migration on a disposable database and run the publisher integration test before treating it as production-ready.
