# AutoFlow Contract Service

Dedicated Contract bounded context. Contract owns the lifecycle of legal contract records and immutable order-time legal snapshots. It does not own customer profiles, order state, billing, PDF storage or the identity provider.

## Responsibilities

- Create a tenant-scoped contract draft only for an accepted or contract-pending Order.
- Resolve the authoritative Order through Order Service; never accept customer, vehicle, quote or pricing snapshots from the browser.
- Capture immutable customer, offer, quote and vehicle snapshots from the Order response.
- Keep contract type and version unique per tenant and order.
- Prepare and cancel drafts with an explicit state machine.
- Store audit history and versioned integration events in a transactional Outbox.
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
| POST | /api/v1/contracts/{contractId}/send-for-signature | contracts:manage | Explicitly unavailable until a real provider adapter is configured |

Supported contract types: `vehicle_purchase`, `vehicle_lease`, `vehicle_subscription`. A unique constraint prevents more than one contract for the same tenant/order/type/version.

## Lifecycle and integration

```text
draft ──prepare──> prepared ──provider adapter (future)──> awaiting_signature
  └────────────────cancel────────────────────────────────> cancelled
prepared ─────────cancel─────────────────────────────────> cancelled
awaiting_signature ──verified provider webhook (future)──> signed
```

The current implementation deliberately stops at `prepared`. The send-for-signature endpoint returns HTTP 503 without changing contract state because no e-signature provider is configured. A contract is never marked signed based on a browser request.

The legal snapshot contains the order ID/status at capture, customer ID/version and the customer, offer, quote and vehicle snapshots held by Order. It is immutable through this API. Snapshot size is capped at 128 KiB. No consent records are copied.

## Reliability and security

- PostgreSQL RLS is enabled and forced on every Contract-owned table. Tenant context is taken from the verified JWT principal and set transaction-locally.
- Runtime DB login `autoflow_contract` is `NOBYPASSRLS`; there is no cross-service foreign key.
- Contract creation and state changes write audit and Outbox records in the same database transaction.
- Idempotency is scoped to tenant + actor + key and rejects a key reused for a different command.
- Order lookups have a three-second timeout, do not follow redirects and forward only the caller's bearer token and correlation ID.
- Responses containing legal snapshots use `Cache-Control: no-store`.

## Local development

- Direct API: http://localhost:8090
- Gateway: http://localhost:8088/api/v1/contracts
- Readiness: http://localhost:8090/health/ready

```bash
docker compose --env-file .env.example config --quiet
docker compose --env-file .env.example run --rm postgres-bootstrap
docker compose --env-file .env.example up -d --build contract-service api-gateway
docker compose --env-file .env.example ps contract-service api-gateway
curl -i http://localhost:8090/health/ready
```

The bootstrap migration is idempotent and applies to fresh and existing PostgreSQL volumes. Do not use `docker compose down -v` on a database containing data.

Keycloak imports the realm JSON only when the `autoflow` realm does not already exist. Existing local realms must be updated with the `autoflow-contract` audience mapper and `contracts:read/write/manage` roles; re-importing the realm file does not merge changes.

## Explicit remaining work

This is the first operational slice of the bounded context, not a production-ready legal-document platform yet:

1. Implement and operate the Contract Outbox publisher (the API writes durable events, but they are not yet published to RabbitMQ).
2. Add a document-rendering/storage adapter with immutable PDF artifacts and checksum verification.
3. Integrate a real e-signature provider, signed webhook verification, retries and provider reconciliation.
4. Add legal template/version governance, jurisdiction-specific clauses, retention and legal review.
5. Add end-to-end tests against Order Service, PostgreSQL RLS and Keycloak; run the complete CI workflow.

Do not use this service as legal advice or treat a prepared draft as an executed contract.
