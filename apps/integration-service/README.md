# Integration Service

**Status: scaffold only — health endpoints and local Docker wiring.**

## Responsibility

External-provider adapters, inbound/outbound webhooks, OAuth lifecycle, signature verification, rate limits, retries, circuit breakers and delivery tracking.

## Existing-platform boundary

Stripe and DocuSign already have domain ownership in Payment and Contract. Centralize transport adapters without taking over their business aggregates.

## Implemented endpoints

| Method | Path | Purpose | Responses |
|---|---|---|---|
| GET | /health | Liveness compatibility | 200 |
| GET | /health/live | Process liveness | 200 |
| GET | /health/ready | Readiness plus configured TCP checks | 200, 503 |
| Any | Other paths | Business API not implemented | 404 Problem Details |

Health endpoints are unauthenticated and disclose no credentials. No business endpoints, database schema, migrations, fixtures or domain events are claimed as implemented.

## Local Docker

```bash
docker compose --env-file .env.example up -d --build integration-service
docker compose --env-file .env.example ps integration-service
curl -i http://localhost:8099/health/live
curl -i http://localhost:8099/health/ready
```

Shared bootstrap image: docker/php/skeleton-service.Dockerfile. It uses PHP's built-in HTTP server for local scaffolding only; replace it with the Symfony + RoadRunner runtime before implementing business capabilities.

## Environment

- APP_ENV=dev for this bootstrap.
- HEALTH_DEPENDENCIES: optional semicolon-separated host:port values checked by readiness.
- Configured readiness dependency: rabbitmq:5672.

## Implementation backlog

See docs/FEATURE_BACKLOG.md for planned capabilities, future API design, data ownership, events, reliability, security and acceptance criteria. Do not add generic CRUD without a defined owned use case.
