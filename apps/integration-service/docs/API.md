# Integration Service API

Direct base URL: `http://localhost:8099`. Gateway routes: `/webhooks/{provider}` and `/api/v1/integrations/*`.

## Authentication and conventions

- Business endpoints require a Keycloak RS256 access token: `Authorization: Bearer <token>`.
- The token must have the configured issuer, audience `autoflow-integration`, a tenant claim (`tenant_id` or `tenantId`) and the required role/scope.
- Inbound provider webhooks do **not** use user JWTs. They use the raw request body and `X-Webhook-Signature: sha256=<hex>`, calculated as HMAC-SHA256 with the provider's configured secret.
- JSON errors use an RFC 7807-style object: `type`, `title`, `status`, `detail`.
- Tenant isolation is enforced by PostgreSQL RLS. Provider-to-tenant mapping is server-side and never accepted from the webhook body.

## Endpoint reference

| Method | Path | Auth | Query/path/header/body | Success responses | Error responses |
|---|---|---|---|---|---|
| GET | `/health/live` | Public | None | 200 `{service,status}` | — |
| GET | `/health/ready` | Public | PostgreSQL connectivity | 200 `{service,status,checks}` | 503 database unavailable |
| POST | `/webhooks/{provider}` | Provider HMAC | Path: provider key. Header: `X-Webhook-Signature` (or `X-Signature`). Raw JSON body: `id` or `eventId`, `type` or `eventType`. Max 64 KiB. | 202 new event; 200 duplicate; `{accepted:true,duplicate:boolean}` | 400 invalid JSON; 401 bad signature; 404 provider not configured; 413 body too large; 422 invalid event; 503 persistence failure |
| GET | `/api/v1/integrations/webhook-events` | `integrations:read` | Query `limit`: 1–100, default 25 | 200 `{data:[{provider,eventId,eventType,receivedAt}]}` | 401, 403, 503 |
| POST | `/api/v1/integrations/deliveries` | `integrations:write` | JSON body below; target must be HTTPS and exact-host allowlisted; payload max 16 KiB | 202 `{data:{id,status:"pending"}}` | 400 invalid JSON; 401, 403, 422 validation/allowlist, 503 |
| GET | `/api/v1/integrations/deliveries` | `integrations:read` | None; latest 100 rows | 200 `{data:[{id,provider,eventType,status,attempts,nextAttemptAt,lastError,createdAt}]}` | 401, 403, 503 |
| GET | `/api/v1/integrations/providers` | `integrations:read` | None | 200 `{data:["demo"]}`; names only | 401, 403, 503 |

### Outbound delivery request

```json
{
  "provider": "demo",
  "targetUrl": "https://receiver.example.test/hooks/autoflow",
  "eventType": "offer.published",
  "payload": { "offerId": "offer-demo-001", "status": "published" }
}
```

The provider name is a logical label. The destination hostname must match an entry in `INTEGRATION_OUTBOUND_ALLOWED_HOSTS`; wildcard hosts are not supported. The service returns 202 after persisting the delivery, not after the remote endpoint has accepted it.

### Inbound webhook example

The signature is computed over the exact raw bytes sent on the wire:

```text
X-Webhook-Signature: sha256=<hex(HMAC_SHA256(raw_body, provider_secret))>
```

```json
{
  "id": "evt-provider-001",
  "type": "payment.succeeded",
  "data": { "paymentId": "payment-demo-001" }
}
```

Duplicate detection uses tenant + provider + external event ID. Sensitive fields (password, secret, token, authorization, email, phone, address, IBAN, card, SSN and birth data) are removed from stored inbound payloads.

## Worker behaviour and configuration

- `integration-delivery-worker` claims due rows with `FOR UPDATE SKIP LOCKED`.
- Each outbound body is HMAC-SHA256 signed; TLS certificate and hostname verification stay enabled; redirects are disabled.
- DNS results must be public IP addresses; the selected IPv4 address is pinned for the request to mitigate DNS rebinding.
- Transient failures retry up to five attempts with exponential delays capped at five minutes. A final failure is persisted as `failed`.
- Configure `INTEGRATION_WEBHOOK_SECRET_{PROVIDER}`, `INTEGRATION_PROVIDER_TENANT_{PROVIDER}`, `INTEGRATION_OUTBOUND_ALLOWED_HOSTS` and `INTEGRATION_DELIVERY_SIGNING_SECRET` via secret management.

## Local run and smoke checks

```bash
docker compose --env-file .env.example up -d --build postgres postgres-bootstrap integration-service integration-delivery-worker
curl -i http://localhost:8099/health/ready
sh apps/integration-service/tests/smoke.sh
```

Swagger UI: `http://localhost:8096`. Do not expose provider webhook endpoints without HTTPS at the edge and a unique high-entropy secret per provider.

## Explicit limitations

The current API is a generic signed-webhook integration boundary. Provider OAuth2 flows, provider-specific Stripe/DocuSign/CRM/ERP adapters, rate-limit/Retry-After handling, circuit breakers, reconciliation and operator replay/cancel endpoints are not implemented by this baseline and must not be assumed from the generic webhook API.
