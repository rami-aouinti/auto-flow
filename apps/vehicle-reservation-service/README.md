# Vehicle Reservation Service

Vehicle Reservation owns the allocation lifecycle of physical stock units. It is a separate bounded context from Vehicle Catalog:

- **Vehicle Catalog** owns manufacturer, model, generation and vehicle variant information.
- **Vehicle Reservation** owns whether one physical unit is temporarily held, confirmed, released, expired or fulfilled.
- **Order** owns the commercial order and does not write to reservation tables.

## Domain rules

- A hold has an explicit expiry timestamp.
- A hold can be confirmed only before its expiry.
- Only held reservations expire automatically.
- Confirmed reservations do not expire automatically; they must be released or fulfilled through an explicit business action.
- A physical unit can have only one active reservation (held or confirmed), enforced by a PostgreSQL partial unique index across tenants.
- A reservation can be fulfilled only after confirmation.
- Terminal reservations cannot be reactivated; a new reservation is a new aggregate.

The vehicle_unit_id identifies a physical stock unit (for example a VIN-backed fleet item). It is deliberately different from vehicle_variant_id, which identifies a catalogue configuration. A catalogue variant is not itself a reservable car.

## Persistence design

The initial PostgreSQL schema includes:

- vehicle_reservations: aggregate state and optimistic version.
- vehicle_reservation_event_inbox: event deduplication.
- vehicle_reservation_audit_events: append-only business history.
- vehicle_reservation_idempotency: command idempotency records.
- vehicle_reservation_outbox: transactional event publication queue.

All tenant-owned tables enforce PostgreSQL Row-Level Security using the transaction-local app.tenant_id setting. The runtime role is not a table owner and does not bypass RLS.

## Current implementation milestone

This first slice contains the domain aggregate, lifecycle unit tests and database schema. It does not yet expose HTTP endpoints, run a RabbitMQ consumer/outbox publisher, or participate in the Order workflow. Those integrations are intentionally the next milestone; the service must not be treated as production-ready until they are implemented and tested.

## Local domain tests

```bash
composer install --working-dir=apps/vehicle-reservation-service
composer test --working-dir=apps/vehicle-reservation-service
```
