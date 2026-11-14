# Integration Service API

Direct base URL: `http://localhost:8099`; inbound provider webhooks use `/webhooks/{provider}`. Business APIs require Keycloak RS256 JWT, issuer, audience `autoflow-integration`, tenant claim and scopes.

| Method | Path | Permission | Arguments | Responses |
|---|---|---|---|---|
| GET | `/health/live` | public | none | 200 |
| GET | `/health/ready` | public | PostgreSQL check | 200, 503 |
| POST | `/webhooks/{provider}` | provider HMAC | raw JSON body; `X-Webhook-Signature: sha256={hex HMAC-SHA256}`; body `id` or `eventId`, `type` or `eventType` | 202 accepted, 200 duplicate, 401 invalid signature, 404 unconfigured provider, 422 invalid event |
| GET | `/api/v1/integrations/webhook-events` | `integrations:read` | `limit` (1–100, default 25) | 200, 401, 403 |
| POST | `/api/v1/integrations/deliveries` | `integrations:write` | JSON: provider, HTTPS targetUrl (host must be allowlisted), eventType, payload (max 16 KiB) | 202 queued, 401, 403, 422 |
| GET | `/api/v1/integrations/deliveries` | `integrations:read` | none (latest 100) | 200, 401, 403 |
| GET | `/api/v1/integrations/providers` | `integrations:read` | none; returns configured provider names only | 200, 401, 403 |

Outbound delivery processing runs in a separate worker. It signs the request body with HMAC-SHA256, accepts only allowlisted HTTPS hosts, pins a public DNS result, verifies TLS, disables redirects and retries up to five times with exponential backoff. Provider secrets are read from environment variables `INTEGRATION_WEBHOOK_SECRET_{PROVIDER}`; tenant mapping is configured with `INTEGRATION_PROVIDER_TENANT_{PROVIDER}`. Secrets are never returned by the API. Webhook deliveries are idempotent by tenant + provider + external event ID.

## Local run

```bash
docker compose --env-file .env.example up -d --build postgres postgres-bootstrap integration-service
curl http://localhost:8099/health/ready
```

Do not expose webhook endpoints without HTTPS at the edge and a provider-specific secret. Configure a unique high-entropy secret per provider.
