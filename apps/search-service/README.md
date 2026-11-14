# AutoFlow Search Service

Elasticsearch-backed search projection for the shared vehicle catalogue.

## Implemented capabilities

- Authenticated vehicle search with relevance ranking and typo tolerance.
- Filters for manufacturer, fuel type, powertrain, body style, transmission, power and seats.
- Pagination, deterministic sorting and aggregations (facets).
- Lightweight autocomplete suggestions.
- Explicit index mapping and a repeatable catalogue reindex command.
- Readiness check against Elasticsearch; JWT verification uses the shared CoreBundle and Keycloak JWKS.

The indexed catalogue is shared master data. Search stores no customer PII, tenant-specific data, offers, orders or contracts.

## API

Business routes require a Keycloak bearer token with either `catalog:read` or `search:read`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/search/vehicles` | Search published vehicle variants |
| GET | `/api/v1/search/vehicles/suggest?q=gol` | Suggestions |
| GET | `/health`, `/health/live`, `/health/ready` | Health probes |

Example:

```http
GET /api/v1/search/vehicles?q=volkswagen%20golf&fuelType=diesel&page=1&limit=10
Authorization: Bearer <access-token>
```

Query parameters: `q`, `manufacturer`, `fuelType`, `powertrain`, `bodyStyle`, `transmission`, `minPowerKw`, `maxPowerKw`, `seats`, `sort`, `page`, `limit`. Sort values: `relevance`, `power_asc`, `power_desc`, `name`.

## Initial index build / reindex

The catalogue database role is read-only from this service. Reindexing is safe to repeat, builds a fresh versioned index, and atomically switches the stable alias only after all batches succeed. The previous index is retained for rollback.

```bash
docker compose --env-file .env.example exec search-service php bin/reindex-vehicles.php
```

Check indexed documents:

```bash
curl -fsS 'http://localhost:9200/autoflow-vehicles/_count'
```

## Tests

Run the service unit tests inside the local development image:

```bash
docker compose --env-file .env.example exec search-service composer test
```

The test suite covers Elasticsearch connectivity and response decoding. Full integration and load tests against a real Elasticsearch cluster remain part of the production-readiness backlog.

## Local runtime

```bash
docker compose --env-file .env.example up -d --build search-service
docker compose --env-file .env.example exec search-service php bin/reindex-vehicles.php
curl -i http://localhost:8097/health/ready
```

The gateway route is `/api/v1/search/*`; direct local diagnostics use port 8097.

## Consistency boundary

This first production-shaped slice rebuilds its search projection from the catalogue's published read model. It does not yet claim continuous RabbitMQ event synchronization, delete propagation, alias-based zero-downtime reindexing, or tenant-owned cross-domain projections. Those require versioned catalogue events and are tracked in the feature backlog.
