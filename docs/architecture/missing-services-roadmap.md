# AutoFlow — Missing service bootstrap and implementation roadmap

**Status:** Search has an implemented vehicle-catalogue API and Elasticsearch projection; the other seven services remain health-only scaffolds.

| Service | Responsibility | First real milestone |
|---|---|---|
| Search | Shared vehicle-catalogue search in Elasticsearch | RabbitMQ event synchronization, alias-based reindexing and cross-domain projections |
| Workflow | Temporal workflow definitions and orchestration | Versioned leasing/order workflows, signals, retries and compensations |
| Integration | External-provider adapters and webhooks | Provider registry, secret references, signature verification and retry/DLQ |
| Document | Document metadata, versions, access, retention | Object storage, authorization, checksums and malware scan |
| Audit | Immutable cross-domain audit projection | Event ingestion, immutable retention, tenant/actor search and export |
| Analytics | Read-only business metrics and reports | Event-driven reporting model, KPI definitions and exports |
| Media | Binary media processing and delivery | Object storage, validation, transformations, thumbnails and CDN-ready URLs |
| Configuration | Versioned tenant/business settings and feature flags | Typed schemas, validation, cache invalidation and change events |

## Boundaries

- Search is a projection, never source of truth. Every index and query must enforce tenant scope.
- Workflow coordinates domain services; it does not own Order, Payment, Reservation or Contract aggregates. Temporal workflows must be deterministic; I/O belongs in Activities.
- Integration owns provider transport concerns, not business decisions. Payment and Contract remain owners of their aggregates. Store secret references only.
- Document owns document metadata/lifecycle only after explicit ownership decisions. Contract and Billing remain authoritative for their records; avoid duplicate PDF generation.
- Audit consumes service-owned transactional audit/outbox records; it does not replace local atomic audit writes.
- Analytics is asynchronous/read-only; never scan OLTP databases for reports.
- Media owns binary asset processing. Document owns business-document lifecycle; define MIME/type boundaries.
- Configuration stores validated non-secret settings. Credentials belong in a secret manager.

## Cross-cutting requirements before business endpoints

1. Independent persistence and least-privilege DB role only when the service owns data.
2. Keycloak JWT validation, audience, scopes and tenant context on every business endpoint. Health endpoints disclose no internals.
3. Transactional outbox and consumer inbox/idempotency for event-driven state changes.
4. Versioned event envelopes, compatibility policy, DLQ, replay and poison-message handling.
5. OpenAPI contract, request/response/error examples and endpoint documentation.
6. Unit, integration, contract, security/RLS and end-to-end tests.
7. Correlation IDs, OpenTelemetry, low-cardinality metrics, structured logs and actionable alerts.
8. Kubernetes resource limits, graceful shutdown, probes, non-root runtime and secret injection.
9. Load tests and explicit SLOs before claiming performance.
10. Retention, privacy, deletion/export obligations and tenant-isolation review.

## Current scaffold endpoints

Workflow, Integration, Document, Audit, Analytics, Media and Configuration expose health-only endpoints (`/health`, `/health/live`, `/health/ready`). Search is a real Symfony/RoadRunner API with authenticated vehicle search and autocomplete at `/api/v1/search/vehicles` and `/api/v1/search/vehicles/suggest`; it builds its projection from the published Vehicle Catalog read model. Continuous event-driven synchronization and blue/green reindexing remain open.

## Local run

```bash
docker compose --env-file .env.example config --quiet
docker compose --env-file .env.example up -d --build workflow-service integration-service document-service audit-service analytics-service media-service configuration-service
sh scripts/check-skeleton-health.sh
```

Loopback ports: Workflow 8098, Integration 8099, Document 8100, Audit 8101, Analytics 8102, Media 8103, Configuration 8104. Search runs on 8097 as a Symfony/RoadRunner API.

## Implementation sequence

1. Search: catalogue event synchronization and alias-based reindexing.
2. Workflow: end-to-end leasing workflow and activity ownership.
3. Integration: provider adapters and webhook reliability.
4. Document + Media: decide ownership, then object storage and scanning.
5. Audit: immutable projection and compliance queries.
6. Configuration: typed tenant settings and safe cache invalidation.
7. Analytics: event-based reporting model and KPI catalogue.

Every milestone requires tests, migration/rollback notes, OpenAPI updates and updated service documentation.
