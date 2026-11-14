# Document Management Service

**Status: functional implementation with local S3-compatible storage, tenant isolation, legal hold and retention workers.** Production sign-off still requires the integration, load, recovery and key-rotation tests listed in `docs/FEATURE_BACKLOG.md`.

## Responsibility

Document Service owns business-document binaries and metadata lifecycle only. Contract and Billing remain authoritative for business workflows and PDF generation; they reference Document IDs rather than duplicating file storage or business lifecycle.

## Runtime and dependencies

- PHP 8.4 CLI, PDO PostgreSQL, Fileinfo, mbstring, ZIP and cURL.
- PostgreSQL schema `document_service`; application role `autoflow_document` with forced tenant RLS.
- Separate `autoflow_document_maintenance` role with narrowly granted document-table access and `BYPASSRLS` for cross-tenant retention processing.
- ClamAV malware scanner; uploads fail closed if scanning is unavailable or not clean.
- S3-compatible object storage. Local Compose starts MinIO, creates a private bucket and a least-privilege service user.
- Object-level SSE-C AES-256 encryption. Back up `DOCUMENT_S3_SSE_C_KEY_BASE64` securely and keep it stable; losing it makes objects unreadable.
- RabbitMQ topic exchange and durable queue `autoflow.document.events`; transactional outbox delivery is at-least-once.
- Keycloak RS256 JWT, audience `autoflow-document`, tenant claim `tenant_id` and `documents:read|write|manage` permissions.

## API

See [API Reference](docs/API.md) and [OpenAPI 3.1](docs/openapi.json). The same specification is served by `GET /api/doc.json`.

- `GET /api/v1/documents` — tenant-scoped, paginated list and filters.
- `POST /api/v1/documents` — multipart upload, MIME validation, malware scan and encrypted storage.
- `GET /api/v1/documents/{id}` and `/content` — metadata and clean binary download.
- `GET|POST /api/v1/documents/{id}/versions` — version history and new immutable binary version.
- `GET /api/v1/documents/{id}/audit` — latest 100 audit events.
- `POST /api/v1/documents/{id}/archive` and `DELETE /api/v1/documents/{id}` — lifecycle operations; blocked by legal hold.
- `GET|POST /api/v1/documents/{id}/access-grants` — list/create delegated `read` or `manage` grants.
- `DELETE /api/v1/documents/{id}/access-grants/{grantId}` — revoke a grant.
- `POST|DELETE /api/v1/documents/{id}/legal-hold` — place/release a legal hold.

Health: `/health/live` and `/health/ready`. Readiness checks PostgreSQL, the configured object-storage bucket and ClamAV.

## Storage, retention and events

- `src/storage.php` implements path-style S3 requests with AWS Signature Version 4. `DOCUMENT_STORAGE_DRIVER=local` is available for isolated development; Compose uses S3.
- Each upload is stored under a tenant-hashed object prefix with a random key. Every document version has its own object.
- MinIO local setup creates an application account limited to bucket list/get/put/delete. SSE-C uses a stable 32-byte base64 key.
- `document-retention-worker` runs hourly by default. It deletes all object versions after `retention_until`, records a purge audit entry and emits `DocumentPurged`.
- A legal hold records reason, actor and timestamp and blocks archive, soft-delete and retention purge until explicitly released.
- Owners may grant another Keycloak subject `read` or `manage` access with optional expiry; grants can be revoked.
- The outbox publisher retries unacknowledged events. Consumers must deduplicate by `eventId`.

## Database migrations and fixtures

- `migrations/00_create_document_database_role.sql` — application and maintenance roles.
- `migrations/230-document-schema.sql` — core tables and tenant RLS.
- `migrations/231-document-retention-sharing.sql` — legal hold, grant expiry and purge audit.
- `fixtures/demo.sql` — metadata-only sample rows; no downloadable binary is fabricated.

Fresh PostgreSQL initialization mounts these scripts. `postgres-bootstrap` reapplies the idempotent role/schema/fixture scripts for existing volumes.

## Start locally

```bash
docker compose --env-file .env.example up -d --build \
  postgres postgres-bootstrap rabbitmq clamav \
  document-object-storage document-object-storage-init \
  document-service document-outbox-publisher document-retention-worker

docker compose --env-file .env.example ps \
  document-service document-outbox-publisher document-retention-worker \
  document-object-storage clamav postgres

curl -fsS http://localhost:8100/health/ready
curl -fsS http://localhost:8100/api/doc.json
```

Direct API: `http://localhost:8100`. Gateway API: `http://localhost:8088/api/v1/documents`. MinIO console: `http://localhost:9001` (development only).

## Test locally

```bash
docker compose --env-file .env.example exec -T document-service php /app/bin/test-storage.php
docker compose --env-file .env.example exec -T -e DOCUMENT_STORAGE_INTEGRATION_TEST=1 document-service php /app/bin/test-storage.php
DOCUMENT_TEST_DATABASE_URL=postgresql://autoflow_document:local-only-document-password@127.0.0.1:5432/autoflow \
DOCUMENT_MAINTENANCE_TEST_DATABASE_URL=postgresql://autoflow_document_maintenance:local-only-document-maintenance-password@127.0.0.1:5432/autoflow \
php apps/document-service/bin/test-database.php
```

## Kubernetes

`infrastructure/kubernetes/document-service.yaml` deploys the API, outbox publisher, retention worker and API HPA into namespace `autoflow`. It expects an externally managed Secret named `autoflow-document-secrets` with keys:

- `document-database-url`
- `document-maintenance-database-url`
- `s3-access-key`, `s3-secret-key`, `s3-sse-c-key-base64`
- `rabbitmq-user`, `rabbitmq-password`

Set the ConfigMap's S3 endpoint, bucket, region, Keycloak issuer/JWKS URL, RabbitMQ and ClamAV addresses for the target cluster. Build and publish the service image before applying the manifest.

## Security and production caveats

- Replace every `.env.example` credential and the example SSE-C key before any non-local deployment. Prefer a managed object store, dedicated IAM identity, managed key service and tested key-rotation/re-encryption process.
- Losing an SSE-C key makes the associated objects permanently unreadable. Store and back up it independently from the object store.
- The maintenance role has `BYPASSRLS` but only explicit privileges on Document tables. Protect its credential as a high-impact secret and monitor its use.
- Retention purge is irreversible. Legal hold must be applied before the retention deadline.
- Existing Keycloak realms need the `autoflow-document` audience mapper and `documents:*` roles imported/updated; users need a newly issued access token.
- Demo fixture records are metadata-only. Use actual uploads to create binary objects.
- See `docs/FEATURE_BACKLOG.md` for remaining integration, observability, load, recovery and compliance work.
