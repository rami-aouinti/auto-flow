# Vehicle Reservation Service

Vehicle Reservation owns the allocation lifecycle of physical stock units. It is a separate bounded context from Vehicle Catalog:

- **Vehicle Catalog** owns manufacturer, model, generation and vehicle variant information.
- **Vehicle Reservation** owns whether one physical unit is temporarily held, confirmed, released, expired or fulfilled.
- **Order** owns the commercial order and does not write to reservation tables.

A `vehicleUnitId` identifies one physical stock unit (for example a VIN-backed fleet item). A `vehicleVariantId` identifies a catalogue configuration. A catalogue variant is not itself reservable stock.

## Domain rules

- A hold has an explicit expiry timestamp.
- A hold can be confirmed only before its expiry.
- Only held reservations are eligible for automatic expiry; confirmed reservations require an explicit release or fulfilment action.
- PostgreSQL's partial unique index allows only one active (held or confirmed) reservation per physical unit across all tenants.
- A reservation can be fulfilled only after confirmation.
- Terminal reservations cannot be reactivated; a new reservation is a new aggregate.

## Physical stock registry and Saga commands

The additive migration `230-vehicle-stock-registry-and-allocation.sql` creates `vehicle_stock_units`. Each row is one real physical vehicle unit, not a catalogue variant. A unit belongs to one tenant and variant and has a globally unique unit ID and optional VIN. The stock lifecycle is `available -> reserved -> available` (release/expiry) or `available -> reserved -> sold` (fulfilment).

The allocation function `vehicle_reservation_hold_available(tenantId, vehicleVariantId, orderId, expiresAt)` selects one available unit using `FOR UPDATE SKIP LOCKED`, creates the reservation, updates stock, and writes audit + Outbox records in one database transaction. Repeating the same order/variant returns the existing active reservation. It never allocates the same physical unit twice. The companion `vehicle_reservation_release_for_order` is the Saga compensation; it releases only held/confirmed reservations and emits a durable Outbox event. A confirmed reservation cannot be compensated after fulfilment.

The dedicated `reservation-saga-command-consumer` reads durable commands from `autoflow.vehicle-reservation.commands`. It supports:
- `reservation.hold.requested.v1`: allocate a real unit for an order and variant.
- `reservation.release.requested.v1`: compensate an earlier hold/confirmation.
- `reservation.hold.rejected.v1`: emitted when no matching available unit exists.

Successful state changes are emitted by the transactional Outbox to `autoflow.vehicle-reservation.events`. RabbitMQ delivery is at-least-once; consumers must deduplicate by event ID. The command consumer uses a narrowly scoped database worker role that can execute only the stock allocation/release functions; it has no direct table access.

### Seed local demo stock

After applying the migrations, seed three physical demo units for a real catalogue variant UUID:

```bash
docker compose --env-file .env.example up -d postgres postgres-bootstrap
docker compose --env-file .env.example run --rm vehicle-reservation-service \
  php bin/seed-demo-stock.php tenant-demo YOUR_VEHICLE_VARIANT_UUID
```

The seeder is deterministic and safe to run repeatedly. It creates three units for the supplied tenant and variant. Replace the placeholder with a UUID that exists in your Vehicle Catalog data.

## HTTP API

The service listens on port `8092`; through the gateway use `http://localhost:8088/api/v1/reservations`.

| Method | Endpoint | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/reservations` | `reservations:read` | List tenant reservations; supports `status`, `limit`, `offset` |
| POST | `/api/v1/reservations` | `reservations:write` | Hold a physical stock unit |
| GET | `/api/v1/reservations/{id}` | `reservations:read` | Read a reservation |
| GET | `/api/v1/reservations/{id}/history` | `reservations:read` | Read audit history |
| POST | `/api/v1/reservations/{id}/confirm` | `reservations:write` | Confirm a hold before expiry |
| POST | `/api/v1/reservations/{id}/release` | `reservations:write` | Release a held or confirmed unit |
| POST | `/api/v1/reservations/{id}/fulfill` | `reservations:manage` | Mark a confirmed reservation fulfilled |

Every write requires an `Idempotency-Key`. State transitions additionally require the current quoted `If-Match` version from the response `ETag`. A stale version returns `412`, a conflicting transition or stock allocation returns `409`, and invalid input returns `400` or `422` using Problem Details.

Create a hold:

```bash
curl -i -X POST http://localhost:8088/api/v1/reservations \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Idempotency-Key: reservation-demo-001' \
  -H 'Content-Type: application/json' \
  -d '{"vehicleUnitId":"11111111-1111-4111-8111-111111111111","vehicleVariantId":"22222222-2222-4222-8222-222222222222","orderId":"33333333-3333-4333-8333-333333333333","expiresAt":"2026-11-08T12:00:00Z"}'
```

Then transition using the returned reservation ID and ETag:

```bash
curl -i -X POST http://localhost:8088/api/v1/reservations/RESERVATION_ID/confirm \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Idempotency-Key: reservation-demo-confirm-001' \
  -H 'If-Match: "1"'
```

## Persistence and security

The PostgreSQL schema includes:

- `vehicle_reservations`: aggregate state, timestamps and optimistic version.
- `vehicle_reservation_event_inbox`: event deduplication foundation.
- `vehicle_reservation_audit_events`: append-only business history.
- `vehicle_reservation_idempotency`: replay-safe command records.
- `vehicle_reservation_outbox`: transactional event publication records. Events are published to the durable topic exchange `autoflow.vehicle-reservation.events` with publisher confirms; the durable queue of the same name retains messages until consumers bind their own queues.

Tenant-owned tables enforce PostgreSQL Row-Level Security using transaction-local `app.tenant_id`. The API runtime role is not a table owner and does not bypass RLS. The expiry worker login can only execute a narrowly scoped function; the function runs under a separate no-login `BYPASSRLS` owner. The publisher login can only execute the Outbox claim/ack/retry functions and cannot read reservation tables directly. The API validates Keycloak RS256 tokens against issuer, JWKS and audience `autoflow-vehicle-reservation`; tenant and permission checks are performed per request.

## Local setup and tests

```bash
docker compose --env-file .env.example up -d --build vehicle-reservation-service reservation-expiry-worker reservation-outbox-publisher reservation-saga-command-consumer
composer install --working-dir=apps/vehicle-reservation-service
RESERVATION_TEST_DATABASE_URL=postgresql://autoflow_reservation:local-only-reservation-password@127.0.0.1:5432/autoflow \
RESERVATION_WORKER_TEST_DATABASE_URL=postgresql://autoflow_reservation_worker:local-only-reservation-worker-password@127.0.0.1:5432/autoflow \
RESERVATION_PUBLISHER_TEST_DATABASE_URL=postgresql://autoflow_reservation_publisher:local-only-reservation-publisher-password@127.0.0.1:5432/autoflow \
composer test --working-dir=apps/vehicle-reservation-service
curl -i http://localhost:8092/health/ready
docker compose --env-file .env.example exec -T vehicle-reservation-service php bin/smoke-test-rabbitmq.php
```

The service now includes the automatic expiry worker and transactional Outbox publisher. Expiry changes, audit records and Outbox events are committed atomically. The publisher uses durable persistent messages, publisher confirms, retry backoff and a terminal failed state after 12 attempts. Delivery is at-least-once: consumers must deduplicate by `eventId`. The RabbitMQ smoke test exercises the full expiry → Outbox → exchange → queue path.
