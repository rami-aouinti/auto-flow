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
| GET | `/health/ready` | public | checks PostgreSQL + storage | 200 | 503 |
| GET | `/api/doc.json` | public | OpenAPI JSON | 200 | — |
| GET | `/api/v1/documents` | documents:read | `page?`, `limit?` (1–100), `q?`, `category?`, `entity_type?`, `entity_id?`, `status?` | 200 `{data,meta}` | 401, 403, 500 |
| POST | `/api/v1/documents` | documents:write | multipart: `file` required; `title` required; `category?` (default other); `description?`; `entity_type?`; `entity_id?`; `retention_until?` (ISO-8601) | 201 `{data}` | 400, 401, 403, 413, 415, 422, 503 |
| GET | `/api/v1/documents/{id}` | documents:read | UUID path | 200 `{data}` | 401, 403, 404 |
| DELETE | `/api/v1/documents/{id}` | documents:manage | UUID path; soft-delete metadata | 204 | 401, 403, 404 |
| GET | `/api/v1/documents/{id}/content` | documents:read | UUID path; binary download only after clean scan | binary stream | 401, 403, 404, 423 |
| GET | `/api/v1/documents/{id}/versions` | documents:read | UUID path | 200 `{data: []}` newest first | 401, 403, 404 |
| POST | `/api/v1/documents/{id}/versions` | documents:write | multipart `file` required | 201 `{data}` | 400, 401, 403, 413, 415, 422, 503 |
| GET | `/api/v1/documents/{id}/audit` | documents:read | UUID path; latest 100 audit events | 200 `{data: []}` | 401, 403, 404 |
| POST | `/api/v1/documents/{id}/archive` | documents:manage | UUID path | 200 `{data}` | 401, 403, 404 |

### Upload rules

- Maximum size: 25 MiB.
- MIME types are detected from file content (not trusted from the browser): PDF, JPEG, PNG, plain text and DOCX.
- Every upload is streamed to ClamAV. If the scanner is unavailable or does not return a clean result, the request fails closed and the binary is not stored.
- Files are written outside the web root under a tenant-hashed directory with random storage names and mode 0600.
- SHA-256, byte size and detected MIME type are persisted. The original filename is metadata only.
- Downloads require a valid tenant token and a clean malware scan. Responses use attachment disposition and `X-Content-Type-Options: nosniff`.

### Error format

Errors use RFC 7807-style `application/problem+json` with `type`, `title`, `status` and `detail`. Common statuses: 400 malformed request; 401 missing/invalid JWT; 403 missing permission; 404 unknown or cross-tenant document; 413 upload too large; 415 unsupported media; 422 invalid metadata or infected file; 423 file is not clean; 503 database, identity provider or malware scanner unavailable; 500 unexpected server error.

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

PostgreSQL schema: `document_service`. Tables: `documents`, `document_versions`, `document_access_grants`, `document_audit_events`, `document_outbox_events`. Each query includes tenant scope. The outbox records `DocumentCreated`, `DocumentVersionCreated`, `DocumentArchived` and `DocumentDeleted` atomically with metadata changes. A RabbitMQ publisher/consumer integration is a separate next step; outbox rows are persisted but not yet published.

## Local startup

```bash
docker compose --env-file .env.example up -d --build postgres postgres-bootstrap clamav document-service
docker compose --env-file .env.example ps document-service clamav
curl -i http://localhost:8100/health/ready
curl -i http://localhost:8100/api/doc.json
```

For an existing PostgreSQL volume, `postgres-bootstrap` reapplies the idempotent role/schema/fixture SQL. Fresh database initialization also mounts the same SQL scripts. Demo rows are metadata-only and do not include downloadable binary files.
