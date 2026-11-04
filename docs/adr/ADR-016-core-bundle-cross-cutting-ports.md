# ADR-016: CoreBundle cross-cutting application ports

- **Status:** Proposed
- **Date:** 2026-11-04

## Context

Services need a small number of reusable technical ports without sharing persistence models or business policy. Clock access, event dispatch, transaction boundaries and idempotency storage are recurring infrastructure concerns, but their implementations and operational guarantees vary by bounded context.

## Decision

The CoreBundle exposes framework-independent application interfaces for:
- time through `ClockInterface`;
- integration-event delivery through `EventBusInterface`;
- idempotency storage through `IdempotencyStoreInterface`;
- transaction boundaries through `TransactionManagerInterface`.

Symfony Messenger provides the default event-bus adapter. Database-backed idempotency and transaction adapters remain in the owning service because schema, isolation, retention and transaction ownership are service-specific.

The bundle also provides a minimal HTTP success-response factory and safe gRPC metadata/deadline value helpers. These helpers do not define business DTOs, authentication rules or service RPC contracts.

## Consequences

- Application handlers can be tested with deterministic clocks and in-memory port doubles.
- Event dispatch is transport-independent at the application boundary.
- Idempotency claims must be atomic in concrete adapters; a non-atomic implementation is invalid.
- An event bus dispatch is not a transactional Outbox. Producers requiring reliable publication must persist business state and the Outbox record in the same local transaction.
- gRPC metadata propagation is allow-list based. Authorization credentials must never be copied implicitly between services.
