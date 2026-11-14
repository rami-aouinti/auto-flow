# Analytics Service API

Base URL: `http://localhost:8102`; gateway prefix: `/api/v1/analytics`. Business routes require a Keycloak RS256 JWT with issuer, audience `autoflow-analytics`, tenant claim and the scopes below.

| Method | Path | Permission | Arguments | Responses |
|---|---|---|---|---|
| GET | `/health/live` | public | none | 200 |
| GET | `/health/ready` | public | PostgreSQL connection check | 200, 503 |
| POST | `/api/v1/analytics/events` | `analytics:events:write` | JSON `eventId`, `eventType`, optional ISO `occurredAt`, object `payload` (max 16 KiB) | 201 new, 200 duplicate, 401, 403, 422 |
| GET | `/api/v1/analytics/overview` | `analytics:read` | query `days` (1–365, default 30) | 200, 401, 403 |
| GET | `/api/v1/analytics/series` | `analytics:read` | `days` (1–90), `eventType` (default `offer.created`) | 200, 401, 403 |
| GET | `/api/v1/analytics/export.csv` | `analytics:read` | none; exports at most 10,000 event rows | 200 CSV, 401, 403 |

Overview includes counts by event type, offers created/accepted, conversion percentage, successful payments and latest event timestamp. Series returns daily buckets. The event API is idempotent by tenant + eventId. The service never queries another service's OLTP tables.

## Local run

```bash
docker compose --env-file .env.example up -d --build postgres postgres-bootstrap analytics-service
curl http://localhost:8102/health/ready
```

The local fixture seeds demo events for tenant `tenant-demo`. Configure `AUTOFLOW_JWT_ISSUER`, `AUTOFLOW_JWT_JWKS_URI` and `ANALYTICS_JWT_AUDIENCE` for authenticated API access. Local DB credentials are not production secrets.
