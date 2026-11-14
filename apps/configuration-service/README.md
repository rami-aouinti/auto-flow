# Configuration and Feature Management Service

**Status: scaffold only — health endpoints and local Docker wiring.**

## Responsibility
Typed tenant-scoped settings, configuration versioning, validation, feature flags, change history, cache invalidation and change events.

## Existing-platform boundary
Never store secrets here. Credentials and signing keys belong in a secret manager; pricing policies remain owned by Pricing.

## Implemented endpoints

| Method | Path | Purpose | Responses |
|---|---|---|---|
| GET | /health | Liveness compatibility | 200 |
| GET | /health/live | Process liveness | 200 |
| GET | /health/ready | Readiness plus configured TCP checks | 200, 503 |
| Any | Other paths | Business API not implemented | 404 Problem Details |

No business endpoints, database schema, migrations, fixtures or domain events are implemented yet. Health endpoints are unauthenticated and disclose no credentials.

## Local Docker

```bash
docker compose --env-file .env.example up -d --build configuration-service
curl -i http://localhost:8104/health/live
curl -i http://localhost:8104/health/ready
```

Uses docker/php/skeleton-service.Dockerfile and PHP's built-in server for local scaffolding only. Replace with Symfony + RoadRunner before business implementation.

## Implementation backlog
See docs/FEATURE_BACKLOG.md. Planned features include typed schemas, tenant-scoped settings, immutable versions, feature flags, gradual rollout, Redis cache invalidation, change events and approval/audit workflows. Never store passwords, API keys or private keys in this service.
