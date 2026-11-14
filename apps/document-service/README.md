# Document Management Service

**Status: scaffold only — health endpoints and local Docker wiring.**

## Responsibility

Document metadata, versions, access policies, retention, checksums, malware-scan orchestration, secure upload/download and audit trail.

## Existing-platform boundary

Contract and Billing already generate or reference business documents. Audit ownership before moving or duplicating PDF generation.

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
docker compose --env-file .env.example up -d --build document-service
docker compose --env-file .env.example ps document-service
curl -i http://localhost:8100/health/live
curl -i http://localhost:8100/health/ready
```

Shared bootstrap image: docker/php/skeleton-service.Dockerfile. It uses PHP's built-in HTTP server for local scaffolding only; replace it with the Symfony + RoadRunner runtime before implementing business capabilities.

## Environment

- APP_ENV=dev for this bootstrap.
- HEALTH_DEPENDENCIES: optional semicolon-separated host:port values checked by readiness.
- No external dependency is required for initial health-only readiness.

## Implementation backlog

See docs/FEATURE_BACKLOG.md for planned capabilities, future API design, data ownership, events, reliability, security and acceptance criteria. Do not add generic CRUD without a defined owned use case.
