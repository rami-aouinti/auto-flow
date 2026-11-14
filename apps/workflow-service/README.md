# Workflow Orchestration Service

**Status: scaffold only — health endpoints and local Docker wiring.**

## Responsibility

Temporal workflow definitions and orchestration, signals, retries, timeouts, compensations and execution visibility.

## Existing-platform boundary

Temporal Server and an Order workflow worker already exist. This is only a control-plane scaffold; do not duplicate the existing worker.

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
docker compose --env-file .env.example up -d --build workflow-service
docker compose --env-file .env.example ps workflow-service
curl -i http://localhost:8098/health/live
curl -i http://localhost:8098/health/ready
```

Shared bootstrap image: docker/php/skeleton-service.Dockerfile. It uses PHP's built-in HTTP server for local scaffolding only; replace it with the Symfony + RoadRunner runtime before implementing business capabilities.

## Environment

- APP_ENV=dev for this bootstrap.
- HEALTH_DEPENDENCIES: optional semicolon-separated host:port values checked by readiness.
- Configured readiness dependency: temporal:7233.

## Implementation backlog

See docs/FEATURE_BACKLOG.md for planned capabilities, future API design, data ownership, events, reliability, security and acceptance criteria. Do not add generic CRUD without a defined owned use case.
