# Document Management Service

**Status: functional first implementation.** Tenant-scoped metadata, secure file upload/download, version history, audit trail, retention metadata, malware scanning and transactional outbox are implemented.

## Responsibility

Document Service owns document binaries and their metadata lifecycle. It provides secure upload/download, MIME validation, SHA-256 checksums, tenant isolation, version history, archive/soft-delete, retention metadata and audit events.

Contract and Billing remain owners of their business workflows and PDF generation. They may store a Document ID as a reference; Document Service does not duplicate their domain logic.

## Runtime and dependencies

- PHP 8.4 CLI with PDO PostgreSQL and Fileinfo.
- PostgreSQL schema `document_service`, role `autoflow_document`.
- ClamAV daemon for fail-closed malware scanning.
- Persistent Docker volume `document-data`; binaries are stored outside the web root.
- Keycloak RS256 JWT; audience `autoflow-document`, `tenant_id` claim and `documents:read|write|manage` permissions.

## API

See [API Reference](docs/API.md) for the complete methods, arguments, response codes, upload constraints and examples.

Main routes:
- `GET /api/v1/documents` — tenant-scoped, paginated list and filters.
- `POST /api/v1/documents` — multipart upload.
- `GET /api/v1/documents/{id}` — metadata.
- `GET /api/v1/documents/{id}/content` — clean file download.
- `GET|POST /api/v1/documents/{id}/versions` — version history and replacement upload.
- `GET /api/v1/documents/{id}/audit` — audit history.
- `POST /api/v1/documents/{id}/archive` and `DELETE /api/v1/documents/{id}` — lifecycle operations.

OpenAPI JSON: `/api/doc.json`. Health: `/health/live`, `/health/ready`.

## Database and fixtures

- `migrations/00_create_document_database_role.sql`
- `migrations/230-document-schema.sql`
- `fixtures/demo.sql`

Compose mounts the scripts for fresh PostgreSQL initialization and runs them again through `postgres-bootstrap` for existing volumes. Fixture rows are metadata-only; they deliberately do not pretend to have binary content.

## Start and verify

```bash
docker compose --env-file .env.example up -d --build postgres postgres-bootstrap clamav document-service
docker compose --env-file .env.example ps document-service clamav
curl -i http://localhost:8100/health/live
curl -i http://localhost:8100/health/ready
curl -i http://localhost:8100/api/doc.json
```

The gateway exposes the same API under `http://localhost:8088/api/v1/documents`. Direct port 8100 is bound to localhost only.

## Security notes and boundaries

- JWT signature, issuer, audience, expiry and tenant claim are checked before business routes.
- Every document lookup is scoped by the tenant claim; storage keys are never returned by metadata APIs.
- Upload size is capped at 25 MiB; MIME is detected server-side; unknown formats are rejected.
- ClamAV failures reject uploads; only clean files can be downloaded.
- Random storage names, tenant-hashed directories, restrictive file permissions and attachment-only downloads reduce path and content exposure.
- Audit events and outbox records are written with the metadata transaction.
- The outbox is persisted but no publisher is wired yet. Access-grant tables are reserved for delegated sharing; current API authorization is tenant + permission based.
