# AutoFlow Contract Service

Dedicated Contract bounded context. Contract owns the lifecycle of legal contract records and immutable order-time legal snapshots. It does not own customer profiles, order state, billing, PDF storage or the identity provider.

## Responsibilities

- Create a tenant-scoped contract draft only for an accepted or contract-pending Order.
- Resolve the authoritative Order through Order Service; never accept customer, vehicle, quote or pricing snapshots from the browser.
- Capture immutable customer, offer, quote and vehicle snapshots from the Order response.
- Keep contract type and version unique per tenant and order.
- Prepare and cancel drafts with an explicit state machine.
- Store audit history and versioned integration event envelopes in a transactional Outbox, published to RabbitMQ with publisher confirms.
- Render immutable PDF drafts, upload them to Document Service (malware scan + encrypted object storage), retain the canonical Document ID and verify SHA-256 on download. A local working copy is retained only for the DocuSign adapter.
- Enforce tenant isolation with PostgreSQL RLS and a dedicated non-superuser database role.
- Require an idempotency key for contract creation and lifecycle transitions.

## API

Every route requires a Keycloak access token with the `autoflow-contract` audience, tenant claim and the listed realm permission. Creating a contract also requires `orders:read` and the `autoflow-order` audience because Contract calls Order Service with the caller's verified bearer token.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| POST | /api/v1/contracts | contracts:write + orders:read | Create a draft from an accepted order; Idempotency-Key required |
| GET | /api/v1/contracts | contracts:read | Paginated list; optional status filter |
| GET | /api/v1/contracts/{contractId} | contracts:read | Read contract and immutable legal snapshot |
| GET | /api/v1/contracts/{contractId}/history | contracts:read | Audit history |
| POST | /api/v1/contracts/{contractId}/prepare | contracts:write | draft → prepared; Idempotency-Key required |
| POST | /api/v1/contracts/{contractId}/cancel | contracts:manage | draft/prepared → cancelled; Idempotency-Key required |
| POST | /api/v1/contracts/{contractId}/document | contracts:write + documents:write | Generate and upload an immutable PDF to Document Service; repeated calls return the existing version |
| GET | /api/v1/contracts/{contractId}/document | contracts:read + documents:read | Download canonical PDF bytes from Document Service and verify SHA-256 |
| POST | /api/v1/contracts/{contractId}/send-for-signature | contracts:manage | Create a real DocuSign envelope; requires provider configuration |

Supported contract types: `vehicle_purchase`, `vehicle_lease`, `vehicle_subscription`. A unique constraint prevents more than one contract for the same tenant/order/type/version.

## Lifecycle and integration

```text
draft ──prepare──> prepared ──DocuSign JWT adapter──> awaiting_signature
  └────────────────cancel────────────────────────────────> cancelled
prepared ─────────cancel─────────────────────────────────> cancelled
awaiting_signature ──verified DocuSign Connect webhook──> signed
```

The lifecycle supports `draft` → `prepared` → `awaiting_signature` → `signed`, with cancellation for draft/prepared/declined/voided contracts. PDF generation is server-side and stored on a private persistent volume. The DocuSign adapter uses the JWT OAuth grant, RSA private key, API account ID and a provider idempotency key. It returns 503 without changing state when credentials are missing or the provider does not confirm envelope creation. Only a verified DocuSign Connect HMAC webhook can move an awaiting contract to signed or cancelled; browser requests cannot mark a contract signed. Contract Outbox rows use the same versioned envelope contract consumed by Temporal.

Contract PDF operations require the same caller token to include the `autoflow-document` audience and `documents:write` for generation / `documents:read` for download. The Document Service owner is the authenticated user, so no caller-supplied owner identity is accepted. Run the integration smoke test with a prepared contract UUID using `DOCUMENT_E2E_TOKEN=... CONTRACT_E2E_CONTRACT_ID=... docker compose exec -T contract-service php bin/smoke-test-document-integration.php`.

The legal snapshot contains the order ID/status at capture, customer ID/version and the customer, offer, quote and vehicle snapshots held by Order. It is immutable through this API. Snapshot size is capped at 128 KiB. No consent records are copied.

## Reliability and security

- PostgreSQL RLS is enabled and forced on every Contract-owned table. Tenant context is taken from the verified JWT principal and set transaction-locally.
- Runtime DB login `autoflow_contract` is `NOBYPASSRLS`; there is no cross-service foreign key.
- Contract creation, lifecycle changes, PDF generation and verified signature callbacks write audit and Outbox records in the same database transaction.
- The DocuSign webhook is outside bearer-token routes at `POST /webhooks/docusign`; it requires the configured Connect HMAC key and deduplicates events in a tenant-scoped inbox.
- Document Service owns the canonical PDF bytes, malware scan, encrypted S3 storage, versions and retention. Contract stores the Document UUID and checksum; API downloads are proxied through Document Service and SHA-256 verified. A private local working copy remains for the synchronous DocuSign adapter.
- Idempotency is scoped to tenant + actor + key and rejects a key reused for a different command.
- Order lookups have a three-second timeout, do not follow redirects and forward only the caller's bearer token and correlation ID.
- Responses containing legal snapshots use `Cache-Control: no-store`.

## Local development

- Direct API: http://localhost:8090
- Gateway: http://localhost:8088/api/v1/contracts
- DocuSign Connect webhook through Gateway: http://localhost:8088/webhooks/docusign
- Readiness: http://localhost:8090/health/ready

```bash
docker compose --env-file .env.example config --quiet
docker compose --env-file .env.example run --rm postgres-bootstrap
docker compose --env-file .env.example up -d --build contract-service contract-outbox-publisher api-gateway
docker compose --env-file .env.example ps contract-service api-gateway
curl -i http://localhost:8090/health/ready
```

The bootstrap migration is idempotent and applies to fresh and existing PostgreSQL volumes. Do not use `docker compose down -v` on a database containing data.

DocuSign remains disabled until `DOCUSIGN_INTEGRATION_KEY`, `DOCUSIGN_USER_ID`, `DOCUSIGN_ACCOUNT_ID`, `DOCUSIGN_OAUTH_HOST`, `DOCUSIGN_API_BASE_URL`, `DOCUSIGN_WEBHOOK_HMAC_KEY` and the RSA private key file at `DOCUSIGN_PRIVATE_KEY_PATH` are configured. Mount the private key read-only; never commit it. Configure DocuSign Connect to POST JSON to `/webhooks/docusign` and use the same HMAC key.

For a production deployment, copy `.env.production.example` to a protected `.env.production`, replace every placeholder with the registered legal entity and production DocuSign account values, and keep that file out of Git. The production override mounts the RSA key as a Docker secret at `/run/secrets/docusign_rsa_private_key`:

```bash
docker compose -f compose.yaml -f compose.production.yaml --env-file .env.production config --quiet
docker compose -f compose.yaml -f compose.production.yaml --env-file .env.production up -d --build contract-service contract-outbox-publisher api-gateway
```

The production DocuSign hosts must be `account.docusign.com` and `https://www.docusign.net/restapi`; use the demo hosts only for sandbox testing. Complete JWT impersonation consent and verify the Connect HMAC configuration with a real signed callback before enabling customer signature traffic. The repository intentionally contains no real account IDs, legal tax identity, HMAC secret or private key, so production signing cannot be activated until the operator supplies them.

Keycloak imports the realm JSON only when the `autoflow` realm does not already exist. Existing local realms must be updated with the `autoflow-contract` audience mapper and `contracts:read/write/manage` roles; re-importing the realm file does not merge changes.

## Remaining production gates

This service has a working local slice for draft creation, preparation, PDF generation/storage, signature-provider requests, verified webhooks, audit history and transactional Outbox publication. It is not yet a production-ready legal-document platform:

1. Configure a production DocuSign account, grant JWT impersonation consent, mount its RSA key and HMAC key, then implement provider reconciliation and expired-envelope recovery.
2. Add legal template/version governance, jurisdiction-specific clauses, retention and legal review.
3. Add end-to-end tests against Order Service, PostgreSQL RLS, RabbitMQ publisher confirms, Keycloak and the DocuSign webhook lifecycle.
4. Add operational alerting for terminal Outbox retries, failed provider callbacks and missing signature completion.

Do not use this service as legal advice or treat a prepared draft as an executed contract.
