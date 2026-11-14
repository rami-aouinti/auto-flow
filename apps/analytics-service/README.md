# AutoFlow Analytics Service

**Status: first functional implementation.** Tenant-scoped event projections, KPI endpoints, CSV export and RabbitMQ ingestion are implemented.

## Responsibilities and boundaries

- Consumes versioned domain events asynchronously from the existing domain exchanges.
- Owns only its analytics projection; it never reads another service's OLTP tables.
- Stores event identity, type, occurrence time and bounded JSON payload.
- Provides tenant-scoped KPI overview, daily series and CSV export.
- Uses PostgreSQL row-level security and tenant + event ID idempotency.

## API

See [docs/API.md](docs/API.md) for exact routes, permissions, parameters and responses.

- `GET /api/v1/analytics/overview?days=30` — offers created/accepted, conversion, successful payments and counts by event type.
- `GET /api/v1/analytics/series?eventType=offer.created&days=30` — daily event series.
- `GET /api/v1/analytics/export.csv` — up to 10,000 tenant events.
- `POST /api/v1/analytics/events` — authenticated event ingestion; idempotent by tenant + eventId.
- `GET /health/live`, `GET /health/ready`.

Business routes require Keycloak RS256 JWT verification, audience `autoflow-analytics`, tenant claim and `analytics:read` or `analytics:events:write`.

## Event processing

The `analytics-event-consumer` worker binds a durable queue to the existing AutoFlow domain event exchanges. It normalizes event envelopes, rejects malformed events to a dead-letter queue and deduplicates redelivery using the database unique key. The consumer is at-least-once; downstream projections must remain idempotent.

## Database and fixtures

- `migrations/000_create_analytics_database_role.sql`
- `migrations/001_analytics_schema.sql`
- `fixtures/demo.sql`

The PostgreSQL bootstrap applies the role, schema and demo fixture on startup, including for existing local volumes.

## Start and verify

```bash
docker compose --env-file .env.example up -d --build postgres postgres-bootstrap rabbitmq analytics-service analytics-event-consumer
docker compose --env-file .env.example ps analytics-service analytics-event-consumer
curl -i http://localhost:8102/health/ready
curl -i http://localhost:8088/api/v1/analytics/overview
```

Run smoke checks with `sh apps/analytics-service/tests/smoke.sh`.

Swagger UI: http://localhost:8096. Direct service port 8102 is bound to loopback only.

## Configuration

Set `AUTOFLOW_JWT_ISSUER`, `AUTOFLOW_JWT_JWKS_URI`, RabbitMQ credentials and the analytics database URL for the environment. The local demo realm includes the `autoflow-analytics` audience and analytics permissions. Existing Keycloak realms must be updated/reimported or amended manually; realm import does not overwrite an existing realm.

Local database passwords are development-only. Configure managed secrets, TLS, resource limits and alerting before production.
