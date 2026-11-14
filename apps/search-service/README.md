# AutoFlow Search Service

Elasticsearch-backed search projection for the shared vehicle catalogue.

## Implemented capabilities

- Authenticated vehicle search with relevance ranking and typo tolerance.
- Filters for manufacturer, fuel type, powertrain, body style, transmission, power and seats.
- Pagination, deterministic sorting and aggregations (facets).
- Lightweight autocomplete suggestions.
- Explicit index mapping, versioned index builds and an atomic alias switch for zero-downtime reindexing.
- Readiness check against Elasticsearch; JWT verification uses the shared CoreBundle and Keycloak JWKS.
- RabbitMQ consumer bound to the durable Vehicle Catalog topic exchange.
- Elasticsearch inbox deduplication, delayed retries (up to five) and a dead-letter queue.
- Automatic projection refresh after committed catalogue events, including parent changes and unpublishing.

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

## Event-driven synchronization

The `search-sync-worker` consumes the durable `autoflow.catalog.events` topic exchange through queue `autoflow.search.catalog.sync`. It acknowledges an event only after the projection refresh succeeds and the event ID is stored in the Elasticsearch inbox (`autoflow-search-inbox-v1`). Duplicate deliveries are acknowledged without repeating the work. Failures are retried up to five times with a 15-second delay; exhausted messages are routed to `autoflow.search.catalog.dlq` for inspection and replay.

The first safe consumer implementation rebuilds the complete published catalogue projection for each event. This guarantees that parent model/generation changes and unpublishing are reflected, but it is intentionally a coarse-grained baseline rather than an incremental high-throughput indexer. Incremental per-aggregate updates and event lag metrics remain follow-up work.

Inspect the queues in RabbitMQ Management at http://localhost:15672. The main, retry and dead-letter queues are named `autoflow.search.catalog.sync`, `autoflow.search.catalog.retry` and `autoflow.search.catalog.dlq`.

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
docker compose --env-file .env.example up -d --build search-service search-sync-worker
docker compose --env-file .env.example exec search-service php bin/reindex-vehicles.php
curl -i http://localhost:8097/health/ready
```

The gateway route is `/api/v1/search/*`; direct local diagnostics use port 8097.

## Consistency boundary

Search now refreshes its projection after Vehicle Catalog Outbox events. The consumer rebuilds from the authoritative published read model, so archived/unpublished rows disappear from the next index generation. It does not yet provide incremental per-event indexing, tenant-owned cross-domain projections or automated old-index retention. Those require versioned catalogue events and are tracked in the feature backlog.
