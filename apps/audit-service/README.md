# Audit and Compliance Service

**Status: scaffold only — health endpoints and local Docker wiring.**

## Responsibility

Immutable cross-domain audit projections, actor/tenant/time search, compliance exports, retention and suspicious-activity signals.

## Existing-platform boundary

Domain services must keep their own transactional audit writes. This service consumes events and builds a read model.

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
docker compose --env-file .env.example up -d --build audit-service
docker compose --env-file .env.example ps audit-service
curl -i http://localhost:8101/health/live
curl -i http://localhost:8101/health/ready
```

Shared bootstrap image: docker/php/skeleton-service.Dockerfile. It uses PHP's built-in HTTP server for local scaffolding only; replace it with the Symfony + RoadRunner runtime before implementing business capabilities.

## Environment

- APP_ENV=dev for this bootstrap.
- HEALTH_DEPENDENCIES: optional semicolon-separated host:port values checked by readiness.
- Configured readiness dependency: rabbitmq:5672.

## Implementation backlog

See docs/FEATURE_BACKLOG.md for planned capabilities, future API design, data ownership, events, reliability, security and acceptance criteria. Do not add generic CRUD without a defined owned use case.
