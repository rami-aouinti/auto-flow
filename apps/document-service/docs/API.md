# Document Service — API Reference

**Base URL:** `http://localhost:8100` (gateway: `http://localhost:8088/api/v1/documents`)  
**Authentication:** Keycloak RS256 access token, audience `autoflow-document`, tenant claim `tenant_id`. Send `Authorization: Bearer <access-token>`.  
**Permissions:** `documents:read`, `documents:write`, `documents:manage`.

Document Service owns binary storage and metadata only. Contract and Billing remain owners of their business documents and may reference Document IDs; PDF generation and business lifecycle remain in those bounded contexts.

## Endpoints

| Method | Path | Permission | Arguments / body | Success | Errors |
|---|---|---|---|---|---|
| GET | `/health` | public | none | 200 liveness | — |
| GET | `/health/live` | public | none | 200 process alive | — |
| GET | `/health/ready` | public | checks PostgreSQL, S3 bucket and ClamAV | 200 | 503 |
| GET | `/api/doc.json` | public | OpenAPI JSON | 200 | — |
| GET | `/api/v1/documents` | documents:read | `page?`, `limit?` (1–100), `q?`, `category?`, `entity_type?`, `entity_id?`, `status?` | 200 `{data,meta}` | 401, 403, 500 |
| POST | `/api/v1/documents` | documents:write | multipart: `file` required; `title` required; `category?` (default other); `description?`; `entity_type?`; `entity_id?`; `retention_until?` (ISO-8601) | 201 `{data}` | 400, 401, 403, 413, 415, 422, 503 |
| GET | `/api/v1/documents/{id}` | documents:read, owner or active grant | UUID path | 200 `{data}` | 401, 403, 404 |
| DELETE | `/api/v1/documents/{id}` | owner or documents:manage | UUID path; soft-delete metadata; blocked by legal hold | 204 | 401, 403, 404, 423 |
| GET | `/api/v1/documents/{id}/content` | documents:read or active grant | UUID path; binary download only after clean scan | binary stream | 401, 403, 404, 423 |
| GET | `/api/v1/documents/{id}/versions` | documents:read or active grant | UUID path | 200 `{data: []}` newest first | 401, 403, 404 |
| POST | `/api/v1/documents/{id}/versions` | owner, documents:write or manage grant | multipart `file` required | 201 `{data}` | 400, 401, 403, 413, 415, 422, 503 |
| GET | `/api/v1/documents/{id}/audit` | owner or documents:manage | UUID path; latest 100 audit events | 200 `{data: []}` | 401, 403, 404 |
| POST | `/api/v1/documents/{id}/archive` | owner or documents:manage | UUID path | 200 `{data}` | 401, 403, 404, 423 |
| GET | `/api/v1/documents/{id}/access-grants` | owner or documents:manage | UUID path | 200 `{data: []}` | 401, 403, 404 |
| POST | `/api/v1/documents/{id}/access-grants` | owner or documents:manage | JSON: `subject_id`, `permission` (read/manage), `expires_at?` | 201 `{data}` | 400, 401, 403, 404, 422 |
| DELETE | `/api/v1/documents/{id}/access-grants/{grantId}` | owner or documents:manage | UUIDs in path | 204 | 401, 403, 404 |
| POST | `/api/v1/documents/{id}/legal-hold` | owner or documents:manage | JSON: `reason` (1–2000 chars) | 200 `{data}` | 401, 403, 404, 422 |
| DELETE | `/api/v1/documents/{id}/legal-hold` | owner or documents:manage | UUID path | 200 `{data}` | 401, 403, 404 |

### Upload rules

- Maximum size: 25 MiB.
- MIME types are detected from file content (not trusted from the browser): PDF, JPEG, PNG, plain text and DOCX. DOCX ZIP containers are checked for the required Word document entries rather than accepted as arbitrary ZIP files.
- Every upload is streamed to ClamAV. If the scanner is unavailable or does not return a clean result, the request fails closed and the binary is not stored.
- Files are stored in the private S3-compatible bucket using tenant-hashed object prefixes and random object keys. Server-side encryption uses SSE-C AES-256; the 32-byte key must be backed up securely and kept stable. Local filesystem mode remains available for isolated development.
- SHA-256, byte size and detected MIME type are persisted. The original filename is metadata only.
- Downloads require a valid tenant token and a clean malware scan. Owners and explicit unexpired access grants can access a document; grant permissions are `read` or `manage`. Responses use attachment disposition and `X-Content-Type-Options: nosniff`.

### Retention and legal hold

`retention_until` is an absolute ISO-8601 timestamp. The retention worker checks expired records hourly (configurable), permanently removes current and historical object versions, and emits `DocumentPurged`. A legal hold blocks archive, soft-delete and automatic purge until explicitly released. If `retention_until` is omitted, the document is retained indefinitely until an explicit retention date is set; no legal retention period is guessed by the service. Purge audit metadata is retained separately from the deleted document record.

### Error format

Errors use RFC 7807-style `application/problem+json` with `type`, `title`, `status` and `detail`. Common statuses: 400 malformed request; 401 missing/invalid JWT; 403 missing permission; 404 unknown or cross-tenant document; 413 upload too large; 415 unsupported media; 422 invalid metadata or infected file; 423 file is not clean or a legal hold blocks a lifecycle operation; 503 database, identity provider, object storage or malware scanner unavailable; 500 unexpected server error.

### Example: upload

```bash
curl -X POST http://localhost:8088/api/v1/documents \
  -H "Authorization: Bearer $TOKEN" \
  -F "title=Leasing agreement" \
  -F "category=contract" \
  -F "entity_type=order" \
  -F "entity_id=$ORDER_ID" \
  -F "file=@./agreement.pdf;type=application/pdf"
```

### Example: list and download

```bash
curl "http://localhost:8088/api/v1/documents?page=1&limit=25&category=contract" \
  -H "Authorization: Bearer $TOKEN"
curl -OJ "http://localhost:8088/api/v1/documents/$DOCUMENT_ID/content" \
  -H "Authorization: Bearer $TOKEN"
```

## Data model and events

PostgreSQL schema: `document_service`. Tables: `documents`, `document_versions`, `document_access_grants`, `document_audit_events`, `document_outbox_events`, `document_purge_events`. Each query includes tenant scope. The outbox records `DocumentCreated`, `DocumentVersionCreated`, `DocumentArchived`, `DocumentDeleted`, `DocumentAccessGranted`, `DocumentAccessRevoked`, `DocumentLegalHoldPlaced` and `DocumentLegalHoldReleased` atomically with metadata changes. The retention worker emits `DocumentPurged` after binary deletion. The separate publisher declares the durable topic exchange and queue `autoflow.document.events`, publishes the event envelope and retries unpublished rows until broker acknowledgement. The retention worker emits `DocumentPurged` after expiry and skips legal holds. Delivery is at-least-once: downstream consumers must deduplicate by `eventId`.

## Local startup

```bash
docker compose --env-file .env.example up -d --build postgres postgres-bootstrap rabbitmq clamav document-object-storage document-object-storage-init document-service document-outbox-publisher document-retention-worker
docker compose --env-file .env.example ps document-service document-outbox-publisher document-retention-worker document-object-storage clamav
curl -i http://localhost:8100/health/ready
curl -i http://localhost:8100/api/doc.json
```

For an existing PostgreSQL volume, `postgres-bootstrap` reapplies the idempotent role/schema/fixture SQL. Fresh database initialization also mounts the same SQL scripts. Demo rows are metadata-only and do not include downloadable binary files. Local Compose uses MinIO with a dedicated least-privilege service user and SSE-C encryption; replace all example credentials and the encryption key before any non-local deployment.
