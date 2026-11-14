# Document Management Service — Delivery status

## Implemented

- [x] Tenant-scoped document metadata, categories, lifecycle states, search and pagination.
- [x] Multipart upload with MIME sniffing, 25 MiB limit, SHA-256 and fail-closed ClamAV scanning.
- [x] Private S3-compatible object storage (MinIO in local Compose) with AWS Signature V4 and SSE-C AES-256 encryption.
- [x] Separate MinIO service account with bucket-scoped list/get/put/delete permissions.
- [x] Immutable binary object keys per version, version history and secure download.
- [x] JWT RS256 verification, Keycloak audience, tenant isolation and forced PostgreSQL RLS.
- [x] Owner-based access and delegated read/manage grants with expiry and revocation.
- [x] Legal hold with reason/actor/timestamp; archive, soft-delete and retention purge are blocked while held.
- [x] Scheduled retention worker, permanent deletion of all binary versions, purge audit metadata and DocumentPurged event.
- [x] Transactional outbox publisher to durable RabbitMQ topic exchange/queue; at-least-once delivery documented.
- [x] Audit history, fixtures, central and service OpenAPI 3.1, Swagger docs, health checks and Compose integration.

## Remaining work before production sign-off

- [ ] Add automated API and PostgreSQL integration tests covering JWT signature/expiry, tenant RLS, access grant expiry/revocation, legal hold, concurrent version creation and retention purge.
- [ ] Add S3/MinIO contract tests for SigV4, SSE-C round-trip, denied cross-tenant keys, storage outage and object cleanup after DB rollback.
- [ ] Add event-consumer inbox/idempotency and dead-letter/replay only when a downstream Document event consumer is introduced; current Document Service is a publisher.
- [ ] Add OpenTelemetry spans/metrics for upload, scan, S3 operations, grant decisions, outbox lag and purge batches; wire dashboards and alerts.
- [ ] Add Kubernetes manifests with secret references, resource requests/limits, probes, network policy, persistent/managed object storage and worker deployment.
- [ ] Integrate Document IDs with Contract and Billing workflows through explicit consumer/API contracts; keep PDF generation and business lifecycle in those services.
- [ ] Perform load, malware fixture, disaster-recovery, encryption-key rotation and restore drills.

## Operational constraints

- Demo fixture records are metadata-only; their binary objects are intentionally absent.
- The local MinIO credentials and SSE-C key in .env.example are development-only. Use a managed S3-compatible endpoint, dedicated IAM credentials and a managed KMS/key rotation process outside local development.
- Back up DOCUMENT_S3_SSE_C_KEY_BASE64 securely. Losing the key makes SSE-C objects unreadable; never rotate it without a migration/re-encryption plan.
- The retention worker uses a privileged maintenance DB connection in local Compose to cross tenant RLS. Production must replace it with a narrowly scoped maintenance role and audited privileges.
- Purge is irreversible after object deletion. Legal hold must be applied before the retention deadline when preservation is required.
- The outbox publisher is at-least-once. Future consumers must deduplicate using eventId.
