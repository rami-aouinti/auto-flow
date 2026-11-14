# Audit Service — delivery status

## Implemented

- [x] Authenticated, versioned HTTP ingestion and tenant-scoped read API.
- [x] Keycloak JWT validation via CoreBundle (issuer, audience, signature, expiry).
- [x] Scope checks: audit:write and audit:read.
- [x] Tenant context comes only from the verified principal for HTTP requests.
- [x] PostgreSQL row-level security and forced RLS.
- [x] Append-only database permissions and mutation-blocking trigger.
- [x] Idempotent event ingestion using tenant + eventId.
- [x] Per-tenant SHA-256 hash chain.
- [x] Payload size limit and recursive redaction of common sensitive fields.
- [x] Search filters, pagination, event detail and OpenAPI contract.
- [x] Readiness check against PostgreSQL.
- [x] Durable RabbitMQ consumer bound to existing domain outbox exchanges.
- [x] Producer-envelope normalization, tenant validation and payload redaction.
- [x] Bounded retry, publisher confirms and dead-letter queue.
- [x] Unit tests for payload redaction and producer-envelope normalization.
- [x] RabbitMQ ingestion smoke test in the Quality workflow.
- [x] API Gateway route and central Swagger/OpenAPI registration.

## Remaining production hardening

- [ ] Add privileged-read audit events and export authorization/audit trail.
- [ ] Add CSV/NDJSON compliance export with bounded streaming.
- [ ] Add a chain-verification and evidence-report command.
- [ ] Define retention/legal-hold policy with privacy and legal review; never silently delete immutable evidence.
- [ ] Add a database migration runner and restore/replay runbook.
- [ ] Add full multi-tenant, retry/DLQ and tamper-detection integration tests.
- [ ] Add service-specific metrics, tracing, rate limits and load tests.
