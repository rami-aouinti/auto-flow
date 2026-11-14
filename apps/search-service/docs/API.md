# Search Service — API Reference

**Base URL:** http://localhost:8097  
**Gateway URL:** http://localhost:8088  
**Authentication:** Keycloak RS256 bearer JWT, audience `autoflow-search`; requires `catalog:read` or `search:read`. Vehicle catalogue is shared master data, not tenant-owned.

## Endpoints

| Method | Path | Scope | Description | Responses |
|---|---|---|---|---|
| GET | `/api/v1/search/vehicles` | catalog:read or search:read | Full-text vehicle search, filters, pagination, sorting and facets | 200, 400, 401, 403, 503 |
| GET | `/api/v1/search/vehicles/suggest?q=gol` | catalog:read or search:read | Up to eight autocomplete suggestions | 200, 400, 401, 403, 503 |
| GET | `/health/live` | public | Liveness | 200 |
| GET | `/health/ready` | public | Elasticsearch readiness | 200, 503 |

## Vehicle search parameters

| Parameter | Type | Rules |
|---|---|---|
| `q` | string | Optional, max 120 characters; fuzzy multi-field matching |
| `manufacturer` | string | Exact manufacturer display name |
| `fuelType` | string | Catalogue fuel type |
| `powertrain` | string | Catalogue powertrain |
| `bodyStyle` | string | Catalogue body style |
| `transmission` | string | Catalogue transmission |
| `minPowerKw` | integer | 0..10000 |
| `maxPowerKw` | integer | 0..10000 |
| `seats` | integer | 1..20 |
| `sort` | string | relevance (default), power_asc, power_desc, name |
| `page` | integer | 1..10000; maximum search window is 10,000 hits |
| `limit` | integer | 1..100, default 20 |

Example:

```http
GET /api/v1/search/vehicles?q=volkswagen%20golf&fuelType=diesel&page=1&limit=10
Authorization: Bearer <access-token>
```

Successful responses contain `data`, `meta.page`, `meta.limit`, `meta.total`, and `facets` for fuel types, body styles and manufacturers. Errors use `application/problem+json`. Invalid filters/pagination return 400; missing/invalid tokens 401; missing read scope 403; unavailable Elasticsearch or index returns 503.

## Event-driven synchronization

The `search-sync-worker` binds queue `autoflow.search.catalog.sync` to the Vehicle Catalog topic exchange `autoflow.catalog.events`. It records successfully applied event IDs in Elasticsearch index `autoflow-search-inbox-v1`; duplicate deliveries are acknowledged. Failed processing is retried up to five times with a 15-second delay, then routed to `autoflow.search.catalog.dlq`. The worker currently performs a full projection rebuild per event to guarantee parent updates and unpublishing are reflected; incremental aggregate-aware indexing is a documented optimization still to implement.

## Projection and consistency

Search reads only published manufacturer/model/generation/variant rows from the Vehicle Catalog PostgreSQL read model. It indexes technical and catalogue fields only; customer PII, tenant-specific pricing, offers, orders and contracts are excluded.

Run `php bin/reindex-vehicles.php` to build a fresh versioned index. After all bulk batches succeed, the command atomically moves the `autoflow-vehicles` alias to the new index. A failed build leaves the previous alias untouched and removes the incomplete index. Previous successful generations are retained for rollback; cleanup is an operational backlog item.

Continuous RabbitMQ event consumption and delete propagation are not yet implemented. Reindexing is currently an explicit operational action.
