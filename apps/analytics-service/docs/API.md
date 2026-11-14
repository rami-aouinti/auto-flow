# Analytics Service API

Direct base URL: `http://localhost:8102`; gateway prefix: `/api/v1/analytics`.

## Authentication and conventions

Business endpoints require a Keycloak RS256 access token (`Authorization: Bearer <token>`) with configured issuer, audience `autoflow-analytics`, tenant claim (`tenant_id` or `tenantId`) and the required scope/role. PostgreSQL RLS isolates every query by tenant. Errors use an RFC 7807-style JSON object. CSV responses are streamed as `text/csv`.

## Endpoint reference

| Method | Path | Auth | Parameters/body | Success responses | Error responses |
|---|---|---|---|---|---|
| GET | `/health/live` | Public | None | 200 `{service,status}` | — |
| GET | `/health/ready` | Public | PostgreSQL connectivity | 200 `{service,status,checks}` | 503 |
| POST | `/api/v1/analytics/events` | `analytics:events:write` | JSON body below; payload max 16 KiB | 201 inserted; 200 duplicate; `{accepted:true,created:boolean}` | 401, 403, 422, 503 |
| GET | `/api/v1/analytics/overview` | `analytics:read` | Query `days`: 1–365, default 30 | 200 KPI object | 401, 403, 503 |
| GET | `/api/v1/analytics/series` | `analytics:read` | Query `days`: 1–90, default 30; `eventType`: default `offer.created` | 200 `{data:[{day,value}]}` | 401, 403, 503 |
| GET | `/api/v1/analytics/export.csv` | `analytics:read` | None; maximum 10,000 rows | 200 `text/csv` with `eventType,occurredAt,eventId` | 401, 403, 503 |

### Event ingestion request

```json
{
  "eventId": "offer-demo-001-created",
  "eventType": "offer.created",
  "occurredAt": "2026-11-14T06:00:00Z",
  "payload": { "offerId": "offer-demo-001", "status": "created" }
}
```

Event types are normalized for supported aliases (for example `OfferCreated` to `offer.created`, and `PaymentCaptured` to `payment.succeeded`). Event identity is unique per tenant; duplicate delivery returns 200 and does not create a second row. Sensitive payload keys are redacted before persistence.

### Overview response

```json
{
  "data": {
    "periodDays": 30,
    "eventsByType": { "offer.created": 12, "payment.succeeded": 4 },
    "offersCreated": 12,
    "offersAccepted": 3,
    "offerConversionRate": 25,
    "successfulPayments": 4,
    "lastEventAt": "2026-11-14T06:00:00+00:00"
  }
}
```

### Daily series response

```json
{ "data": [{ "day": "2026-11-14", "value": 4 }] }
```

## Event ingestion worker

`analytics-event-consumer` binds durable queues to the configured AutoFlow domain exchanges, canonicalizes event names, redacts sensitive payload keys and inserts events idempotently by tenant + event ID. Invalid messages are dead-lettered. Transient database failures are requeued; the current consumer does not yet expose an operator replay API or bounded retry counter.

## Local run and smoke checks

```bash
docker compose --env-file .env.example up -d --build postgres postgres-bootstrap rabbitmq analytics-service analytics-event-consumer
curl -i http://localhost:8102/health/ready
sh apps/analytics-service/tests/smoke.sh
```

Swagger UI: `http://localhost:8096`. Configure `AUTOFLOW_JWT_ISSUER`, `AUTOFLOW_JWT_JWKS_URI` and `ANALYTICS_JWT_AUDIENCE` for the target environment. Local fixture data and passwords are development-only.

## Remaining extensions

Governed metric definitions, dashboards/segmentation, XLSX and scheduled reports, projection rebuild/retention, bounded retry and replay tooling, OpenTelemetry metrics/traces, Kubernetes autoscaling and full producer contract/load tests remain future work.
