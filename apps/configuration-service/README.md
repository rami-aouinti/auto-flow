# Configuration and Feature Management Service

Tenant-scoped typed settings, immutable configuration versions, feature flags, rollout evaluation, audit history and transactional outbox events.

## Responsibilities and boundaries
- Owns application/storefront configuration and feature flags.
- Enforces tenant isolation in every database query.
- Keeps immutable version history and optimistic concurrency via `expectedVersion` / ETag.
- Writes audit and outbox records atomically with configuration changes.
- Invalidates matching Redis keys best-effort after a successful write.
- Does **not** store secrets, API keys, credentials or private keys. These belong in a secret manager.
- Does **not** own pricing policies, vehicle data, customer records or contract state.

## API
See [docs/API.md](docs/API.md) for every endpoint, request, response and error contract.

| Method | Path | Purpose |
|---|---|---|
| GET | /health, /health/live, /health/ready | Health and readiness |
| GET | /api/configurations | List tenant settings |
| GET/PUT | /api/configurations/{namespace}/{key} | Read/write typed setting |
| GET | /api/configurations/{namespace}/{key}/versions | Immutable history |
| POST | /api/configurations/{namespace}/{key}/rollback | Roll back by creating a new version |
| GET | /api/feature-flags | List flags |
| GET/PUT | /api/feature-flags/{key} | Read/update a flag |
| GET | /api/feature-flags/{key}/evaluate | Deterministic tenant rollout |
| GET | /api/audit-events | Tenant audit history |

## Local Docker

The service uses PostgreSQL (port 5432) and Redis (port 6379), listens on host port 8104 and runs PHP 8.4 with PDO PostgreSQL.

1. Create the service database role and apply the schema (from repository root):
```bash
docker compose --env-file .env.example exec -T postgres psql -U autoflow -d autoflow < apps/configuration-service/migrations/00_create_configuration_database_role.sql
docker compose --env-file .env.example exec -T postgres psql -U autoflow -d autoflow < apps/configuration-service/migrations/240-configuration-schema.sql
docker compose --env-file .env.example exec -T postgres psql -U autoflow -d autoflow < apps/configuration-service/fixtures/demo.sql
```
Use the actual PostgreSQL admin username configured in your local compose environment if it differs from `autoflow`.

2. Start the service:
```bash
docker compose --env-file .env.example up -d --build configuration-service
curl -i http://localhost:8104/health/ready
```

3. Test a business endpoint (development mode only):
```bash
curl -s http://localhost:8104/api/configurations?namespace=checkout -H 'X-Tenant-Id: tenant-demo' -H 'X-Actor-Id: demo-user'
```
Set `AUTH_MODE=development` in the local compose service environment to skip the trusted-gateway marker. Never enable this mode outside local development.

## Security notes
In gateway mode (default), the gateway must verify JWT issuer, signature, audience, expiry and scopes, strip untrusted identity headers, then inject `X-Autoflow-Auth-Verified: true`, `X-Tenant-Id` and `X-Actor-Id`. Do not expose port 8104 publicly. Production deployment must use managed credentials/secrets and TLS.

## Database
Apply migrations in numerical order. The role migration creates `autoflow_configuration` with a local-only development password; replace it with a managed secret before production. The schema is isolated under `configuration_service`.

## Outbox
The API guarantees outbox persistence in the same transaction as a write. It does not claim RabbitMQ publication until a dedicated publisher/consumer worker is deployed.
