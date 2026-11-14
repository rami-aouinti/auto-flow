# Document Management Service — Remaining backlog

**Implemented baseline:** tenant-scoped metadata API, multipart uploads, server-side MIME validation, 25 MiB limit, SHA-256 checksums, private persistent local storage, ClamAV fail-closed scanning, JWT audience/permissions, PostgreSQL Row-Level Security, version history, archive/soft-delete, audit events, transactional outbox, RabbitMQ publisher, fixtures, OpenAPI, Docker Compose and health checks.

## Implemented

- [x] Define document metadata, tenant ownership, categories and lifecycle states.
- [x] Upload/download with JWT permission checks and tenant isolation.
- [x] MIME sniffing, size limits, SHA-256 and ClamAV malware scanning.
- [x] Private file storage outside the web root.
- [x] Version history and metadata retention date.
- [x] Audit trail and transactional outbox.
- [x] RabbitMQ publisher with durable exchange/queue and retry.
- [x] PostgreSQL schema, least-privilege role and forced RLS.
- [x] Demo fixtures, endpoint documentation, central OpenAPI and CI lint/build checks.

## Remaining production-hardening work

- [ ] Replace the local Docker volume adapter with encrypted S3-compatible object storage (MinIO for local development, managed object storage in production).
- [ ] Implement retention enforcement, legal holds, scheduled purge and verifiable deletion.
- [ ] Implement delegated document sharing/access-grant endpoints and ownership rules.
- [ ] Add consumer inbox/idempotency, event schema versioning, dead-letter/replay operations and consumer contract tests.
- [ ] Integrate Document IDs into Contract and Billing workflows without moving PDF generation or domain ownership.
- [ ] Add OpenTelemetry traces/metrics, structured security audit logging and operational dashboards.
- [ ] Add automated API, database integration, JWT/RLS isolation, malware, failure/retry and load tests.
- [ ] Add Kubernetes manifests, resource limits, secret injection, rolling deployment and storage lifecycle policy.

## Operational caveats

- Demo fixture records contain metadata only; their binary files are intentionally absent.
- The current storage adapter is a persistent local Docker volume, not encrypted object storage.
- The outbox publisher is at-least-once. Consumers must deduplicate using `eventId`.
- ClamAV must be healthy before uploads are accepted. The service readiness endpoint checks PostgreSQL, writable storage and the ClamAV TCP ping.
- The Keycloak realm import adds `autoflow-document` and the `documents:*` roles. Existing realms need the mapper/roles updated and users need a newly issued token.
