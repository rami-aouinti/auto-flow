# Vehicle Reservation Service

Vehicle Reservation owns the allocation lifecycle of physical stock units. It is a separate bounded context from Vehicle Catalog:

- **Vehicle Catalog** owns manufacturer, model, generation and vehicle variant information.
- **Vehicle Reservation** owns whether one physical unit is temporarily held, confirmed, released, expired or fulfilled.
- **Order** owns the commercial order and does not write to reservation tables.

A `vehicleUnitId` identifies one physical stock unit (for example a VIN-backed fleet item). A `vehicleVariantId` identifies a catalogue configuration. A catalogue variant is not itself reservable stock.

## Domain rules

- A hold has an explicit expiry timestamp.
- A hold can be confirmed only before its expiry.
- Only held reservations expire automatically; confirmed reservations require an explicit release or fulfilment action.
- PostgreSQL's partial unique index allows only one active (held or confirmed) reservation per physical unit across all tenants.
- A reservation can be fulfilled only after confirmation.
- Terminal reservations cannot be reactivated; a new reservation is a new aggregate.

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
- `vehicle_reservation_outbox`: transactional event publication records.

Tenant-owned tables enforce PostgreSQL Row-Level Security using transaction-local `app.tenant_id`. The runtime role is not a table owner and does not bypass RLS. The API validates Keycloak RS256 tokens against issuer, JWKS and audience `autoflow-vehicle-reservation`; tenant and permission checks are performed per request.

## Local setup and tests

```bash
docker compose --env-file .env.example up -d --build vehicle-reservation-service
composer install --working-dir=apps/vehicle-reservation-service
composer test --working-dir=apps/vehicle-reservation-service
curl -i http://localhost:8092/health/ready
```

The current delivery covers the domain lifecycle, tenant-secured REST API, transactional audit/idempotency/outbox writes, PostgreSQL RLS and gateway/Keycloak/Compose wiring. RabbitMQ outbox publishing and the scheduled expiry worker remain separate follow-up work; outbox rows are persisted but are not yet emitted to RabbitMQ by this milestone.
