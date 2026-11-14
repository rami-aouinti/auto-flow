# Configuration Service — Delivery status

## Implemented
- [x] Tenant-scoped typed configuration values (JSON scalar/object/array/null).
- [x] Immutable version history and rollback by creating a new version.
- [x] Optimistic concurrency through expectedVersion and ETag response headers.
- [x] Tenant-scoped feature flags with deterministic percentage rollout and allowlist.
- [x] Audit events and transactional outbox persistence.
- [x] Best-effort Redis cache invalidation.
- [x] PostgreSQL schema, role migration, demo fixtures, Dockerfile and API documentation.
- [x] Health, liveness and database readiness endpoints.

## Follow-up hardening
- [ ] Deploy and monitor a dedicated transactional outbox publisher to RabbitMQ; add retry/backoff and dead-letter handling.
- [ ] Add automated PHPUnit integration and concurrency tests against PostgreSQL and Redis.
- [ ] Replace gateway identity headers with direct JWT/JWKS validation if the service is ever exposed outside the trusted internal network.
- [ ] Add schema registry / JSON Schema validation per namespace and key.
- [ ] Add approval workflows for selected high-impact settings.
- [ ] Add OpenTelemetry traces, metrics and structured request correlation.
- [ ] Add per-key retention policies and audit export controls.

Do not store passwords, API keys, signing keys or access tokens in this service.
