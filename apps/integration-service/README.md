# AutoFlow Integration Service

**Status: functional first implementation.** Signed inbound webhooks, tenant-scoped event tracking, outbound webhook queueing and a bounded retry worker are implemented.

## Responsibilities and boundaries

- Receives provider webhooks and verifies provider-specific HMAC-SHA256 signatures against configured secrets.
- Maps providers to tenants using server-side configuration; a webhook payload cannot choose its tenant.
- Deduplicates inbound events by tenant + provider + external event ID.
- Queues outbound webhook deliveries for allowlisted HTTPS hosts.
- Signs outbound deliveries, verifies TLS, pins the resolved public IP, disables redirects and applies bounded retries with exponential backoff.
- Keeps provider secrets in environment/secret management; API responses expose provider names only.
- Does not take ownership of payment, contract or customer business aggregates.

## API

See [docs/API.md](docs/API.md) for exact request/response contracts.

- `POST /webhooks/{provider}` — provider-signed inbound webhook.
- `GET /api/v1/integrations/webhook-events` — tenant event history.
- `POST /api/v1/integrations/deliveries` — enqueue outbound webhook.
- `GET /api/v1/integrations/deliveries` — delivery status and retry history.
- `GET /api/v1/integrations/providers` — configured provider names only.
- `GET /health/live`, `GET /health/ready`.

Business routes require Keycloak RS256 JWT, audience `autoflow-integration`, tenant claim and `integrations:read|write`. Provider webhook endpoints use HMAC authentication rather than user JWT.

## Configuration

For each provider, configure:
- `INTEGRATION_WEBHOOK_SECRET_{PROVIDER}` — inbound signature secret.
- `INTEGRATION_PROVIDER_TENANT_{PROVIDER}` — trusted tenant mapping.

For outbound deliveries configure:
- `INTEGRATION_OUTBOUND_ALLOWED_HOSTS` — comma-separated exact HTTPS host allowlist; no wildcard hosts.
- `INTEGRATION_DELIVERY_SIGNING_SECRET` — high-entropy signing secret.

The local Compose file includes a demo provider and development-only secrets. Replace them before any shared or production deployment.

## Database and workers

- `migrations/000_create_integration_database_role.sql`
- `migrations/001_integration_schema.sql`
- `fixtures/demo.sql`
- `integration-delivery-worker` claims pending deliveries with `FOR UPDATE SKIP LOCKED`, signs requests and retries transient failures up to five attempts.

The worker uses a dedicated restricted database role with RLS bypass only for queue processing; it has no schema ownership or DDL permissions. Keep its credentials isolated from the HTTP service.

## Start and verify

```bash
docker compose --env-file .env.example up -d --build postgres postgres-bootstrap integration-service integration-delivery-worker
docker compose --env-file .env.example ps integration-service integration-delivery-worker
curl -i http://localhost:8099/health/ready
```

Swagger UI: http://localhost:8096. The webhook route is also exposed through the API gateway at `http://localhost:8088/webhooks/{provider}`.

## Security and remaining provider-specific work

Outbound URLs must use HTTPS and match the explicit allowlist. The worker rejects non-public DNS results, pins the selected IP for the request, verifies certificates and never follows redirects. Do not log webhook secrets or full sensitive payloads.

Provider-specific OAuth2 authorization-code/client-credentials flows, credential rotation, provider adapters beyond the generic webhook contract, provider-specific rate-limit policies and automated reconciliation remain integration extensions; they must be added per provider rather than storing credentials in this service's database.
