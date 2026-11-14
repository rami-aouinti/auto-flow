# Audit Service — delivery status

## Implemented in the first production-shaped slice

- [x] Authenticated, versioned HTTP ingestion and tenant-scoped read API.
- [x] Keycloak JWT validation via CoreBundle (issuer, audience, signature, expiry).
- [x] Scope checks: audit:write and audit:read.
- [x] Tenant context comes from the verified principal; tenant cannot be supplied in the body.
- [x] PostgreSQL row-level security and forced RLS.
- [x] Append-only database permissions and mutation-blocking trigger.
- [x] Idempotent event ingestion using tenant + eventId.
- [x] Per-tenant SHA-256 hash chain.
- [x] Payload size limit and recursive redaction of common sensitive fields.
- [x] Search filters, pagination, event detail and OpenAPI contract.
- [x] Readiness check against PostgreSQL.

## Remaining production hardening

- [ ] Publish a versioned integration-event envelope and onboard each producer through its transactional outbox.
- [ ] Add RabbitMQ consumer, durable queue, inbox deduplication, retry policy and dead-letter queue.
- [ ] Add privileged-read audit events and export authorization/audit trail.
- [ ] Add CSV/NDJSON compliance export with bounded streaming.
- [ ] Add chain-verification and evidence report command.
- [ ] Define retention/legal-hold policy with privacy and legal review; do not silently delete immutable evidence.
- [ ] Add database migration runner and restore/replay runbook.
- [ ] Add integration, contract, tenant-isolation and tamper-detection tests.
- [ ] Add metrics, tracing, rate limits and load tests.
