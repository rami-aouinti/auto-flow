# Search Service — API Reference

**Base URL:** http://localhost:8097  
**Gateway URL:** http://localhost:8088  
**Swagger UI:** http://localhost:8096 (central catalogue)  
**OpenAPI source:** [docs/openapi.yaml](openapi.yaml)  
**Authentication:** Keycloak RS256 bearer JWT, audience `autoflow-search`; requires `catalog:read` or `search:read`. Vehicle catalogue is shared master data, not tenant-owned.

## Endpoints

| Method | Path | Scope | Arguments | Success | Errors |
|---|---|---|---|---|---|
| GET | `/api/v1/search/vehicles` | `catalog:read` or `search:read` | Search/filter/pagination query parameters below | 200 | 400, 401, 403, 503 |
| GET | `/api/v1/search/vehicles/suggest` | `catalog:read` or `search:read` | Required `q` query string, max 120 characters | 200 | 400, 401, 403, 503 |
| GET | `/health/live` | public | none | 200 | — |
| GET | `/health/ready` | public | Elasticsearch connectivity | 200 | 503 |

## Vehicle search parameters

| Parameter | Type | Rules |
|---|---|---|
| `q` | string | Optional, max 120 characters; fuzzy multi-field matching |
| `manufacturer` | string | Exact manufacturer display name |
| `fuelType` | string | Catalogue fuel type |
| `powertrain` | string | Catalogue powertrain |
| `bodyStyle` | string | Catalogue body style |
| `transmission` | string | Catalogue transmission |
| `minPowerKw` / `maxPowerKw` | integer | Each 0..10000 |
| `seats` | integer | 1..20 |
| `sort` | string | `relevance` (default), `power_asc`, `power_desc`, `name` |
| `page` | integer | 1..10000; maximum search window is 10,000 hits |
| `limit` | integer | 1..100, default 20 |

Example:

```http
GET /api/v1/search/vehicles?q=volkswagen%20golf&fuelType=diesel&page=1&limit=10
Authorization: Bearer <access-token>
```

Successful search responses contain `data`, `meta.page`, `meta.limit`, `meta.total`, and facets for fuel types, body styles and manufacturers. Suggestions return at most eight lightweight results. Errors use `application/problem+json`: invalid filters/pagination 400; missing/invalid token 401; missing read scope 403; unavailable Elasticsearch or index 503.

## Event-driven catalogue synchronization

The `search-sync-worker` binds durable queue `autoflow.search.catalog.sync` to the Vehicle Catalog topic exchange `autoflow.catalog.events` with routing key `#`. It records processed event IDs in Elasticsearch inbox index `autoflow-search-inbox-v1`; duplicate deliveries are acknowledged without repeating the projection work.

Failures are retried up to five times with a 15-second delay and then routed to `autoflow.search.catalog.dlq`. The worker acknowledges the source event only after a successful projection refresh and inbox record. Inspect the main, retry and dead-letter queues in RabbitMQ Management at http://localhost:15672.

The current safe baseline rebuilds the complete published catalogue projection for each event. This correctly reflects parent changes and unpublishing, but is more expensive than incremental indexing. Aggregate-aware upserts/deletes and event-lag metrics remain optimization work.

## Projection, consistency and operations

Search reads only published manufacturer/model/generation/variant records from the Vehicle Catalog PostgreSQL read model. It indexes technical and catalogue fields only; customer PII, tenant-specific pricing, offers, orders and contracts are excluded.

The initial/manual reindex builds a fresh versioned index and atomically moves alias `autoflow-vehicles` only after all batches succeed. A failed build leaves the previous alias untouched.

```bash
docker compose --env-file .env.example up -d --build vehicle-catalog-service vehicle-catalog-outbox-publisher search-service search-sync-worker
docker compose --env-file .env.example exec search-service php bin/reindex-vehicles.php
docker compose --env-file .env.example logs -f search-sync-worker vehicle-catalog-outbox-publisher
curl -i http://localhost:8097/health/ready
```

## Security and boundaries

The catalogue is shared master data, so Search does not use tenant-owned search projections. It never returns customer data or tenant-specific commercial information. Do not expose direct service ports publicly; use the API Gateway and production identity/network policies.
