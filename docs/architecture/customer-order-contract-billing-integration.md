# Customer → Order → Contract → Billing integration

This document defines the cross-service contract and rollout rules. It is an integration specification, not a claim that every flow below is already implemented.

## Ownership and identifiers

- **Customer Service** is the system of record for customer identity/profile, addresses and consent. It issues an opaque UUID `customerId` scoped to `tenantId`.
- **Order Service** owns the order lifecycle. An order stores `customerId` as a reference, never a foreign key into the Customer database.
- **Contract Service** (future bounded context) owns contract drafts, generated documents, signature lifecycle and immutable legal snapshots. It references `orderId` and `customerId`; it does not own customer profiles or order state.
- **Billing Service** owns invoices and credit notes. It references `orderId`, `paymentId` and `customerId`, and stores an immutable billing snapshot sufficient to render a legally meaningful invoice.

All identifiers are tenant-scoped. Every API call and event must carry a verified `tenantId`; consumers must reject missing or mismatched tenant context. Never use cross-service database joins.

## Snapshot strategy

At order creation, Order Service resolves the customer through Customer Service using the caller's verified identity/tenant context. It persists:
- `customerId` (stable reference);
- `customerSnapshot` (minimal order-time contact/name details required for the order journey);
- `customerVersion` (Customer optimistic version);
- `capturedAt` timestamp.

The snapshot is historical evidence of what was known when the order was placed. It is not a live mirror. A customer profile change must not silently rewrite an existing order.

When the customer changes billing address before invoice issuance, the billing workflow must explicitly resolve and capture the then-current billing address. Once an invoice is issued, its legal billing snapshot is immutable. Corrections use credit notes/replacement documents according to the applicable legal process, not an in-place update.

Minimize PII in events. Prefer IDs and versions; do not broadcast full addresses, phone numbers or email unless a consumer has a documented need and the event channel is appropriately protected.

## Synchronous APIs

### Customer Service
- `GET /api/v1/customers/{customerId}` — profile and current version.
- `GET /api/v1/customers/{customerId}/addresses` — addresses, including billing/shipping purpose.

Order creation requires a customer read permission/audience in its service token and must verify the customer is active and belongs to the same tenant. The request body may contain `customerId`; tenant identity must come from the verified JWT, never from the body.

### Order Service
Order create command evolves to:
```json
{
  "customerId": "c4f0f45a-9f7a-4c87-9db0-9c5db6b7b001",
  "offerId": "offer-123",
  "quoteId": "quote-456",
  "vehicleId": "variant-789"
}
```
The server resolves and persists the customer snapshot. A client-supplied customer snapshot is ignored/rejected. The order response exposes `customerId`, `customerVersion` and the safe snapshot fields needed by the caller.

### Contract Service (first implementation slice)
- `POST /api/v1/contracts` — create a draft for an accepted or contract-pending order.
- `GET /api/v1/contracts` and `GET /api/v1/contracts/{contractId}`
- `GET /api/v1/contracts/{contractId}/history`
- `POST /api/v1/contracts/{contractId}/prepare`
- `POST /api/v1/contracts/{contractId}/cancel`
- `POST /api/v1/contracts/{contractId}/document` and `GET /api/v1/contracts/{contractId}/document` — generate and download a checksum-verified PDF draft.
- `POST /api/v1/contracts/{contractId}/send-for-signature` — creates a real DocuSign envelope when provider credentials and consent are configured; otherwise returns 503 without changing state.

Contract creation is idempotent on tenant + actor + key and enforces uniqueness on `tenantId + orderId + contractType + contractVersion`. It reads the accepted order with the caller's verified bearer token and captures immutable customer, offer, vehicle, quote and commercial snapshots. Contract audit and Outbox records are committed with each state change. The Outbox publisher is wired to RabbitMQ in Compose/Kubernetes; PDF drafts are rendered to private persistent storage and checksum-verified on download. A DocuSign JWT adapter and HMAC-verified webhook lifecycle exist, but production credentials, consent, provider reconciliation and legal template governance remain deployment/production gates. The API never simulates a successful signature.

### Billing Service
Billing consumes payment lifecycle events, but invoice creation must be enriched with authoritative order/customer billing context. Before issuing an invoice it captures:
- legal customer name (individual/company);
- billing address and country;
- VAT ID when applicable;
- order/contract reference;
- invoice line descriptions, net/tax/gross totals, currency and tax decision metadata.

The current local 19% VAT default is development-only. A production tax decision must be jurisdiction-aware and auditable. Issued invoice snapshots and numbering are immutable.

## Versioned integration events

Use a common envelope (CloudEvents-inspired, with explicit schema version):

```json
{
  "eventId": "uuid",
  "eventType": "customer.profile.updated",
  "eventVersion": 1,
  "occurredAt": "2026-11-07T12:00:00Z",
  "tenantId": "tenant-demo",
  "aggregate": { "type": "customer", "id": "cus_opaque_uuid", "version": 4 },
  "correlationId": "uuid",
  "causationId": "uuid",
  "data": { "changedFields": ["email"] }
}
```

Initial event catalogue:

| Event | Producer | Consumer intent |
|---|---|---|
| `customer.created.v1` | Customer | Search/CRM projections; no automatic order creation |
| `customer.profile.updated.v1` | Customer | Refresh non-legal projections; never mutate historical order/invoice snapshots |
| `customer.archived.v1` | Customer | Prevent new orders/contracts; preserve historical references |
| `order.created.v1` | Order | Start downstream order journey |
| `order.accepted.v1` | Order | Contract preparation may begin |
| `contract.draft.created.v1` | Contract | Operational visibility |
| `contract.signed.v1` | Contract/provider adapter | Order may progress only after verified signature policy |
| `payment.captured.v1` | Payment | Billing creates invoice from order/customer context |
| `billing.invoice.issued.v1` | Billing | Notifications and customer portal projection |
| `billing.credit-note.issued.v1` | Billing | Notifications and accounting projection |

Event type and schema version are separate. Additive optional fields may be introduced without changing the version only if old consumers remain compatible. Breaking semantic or structural changes require a new version and an explicit compatibility/deprecation window.

## Reliability and security requirements

1. Producer writes domain state and Outbox row in one local database transaction.
2. Publisher uses publisher confirms and retries with bounded exponential backoff; terminal failures remain inspectable.
3. Consumer inserts `eventId` into a tenant-scoped Inbox with a unique constraint before applying effects. Duplicate delivery is a no-op.
4. Consumers acknowledge only after the local transaction commits. Poison messages go to a DLQ with alerting.
5. Commands carry an `Idempotency-Key`; event consumers deduplicate by `eventId`. These solve different failure modes.
6. Propagate W3C trace context and `X-Correlation-ID`; do not log access tokens or unnecessary PII.
7. Validate tenant, producer identity, event type and supported schema version. A tenant ID in a message is data, not proof of authorization.
8. Use service-to-service credentials/token exchange with audience restriction. Do not forward broad user tokens to unrelated consumers.

## Recommended rollout

1. **Customer reference in Order — implemented**: nullable `customer_id`, customer version/snapshot columns and index preserve old rows; new order creation requires a UUID customerId.
2. **Order → Customer verification — implemented**: the HTTP reference validator uses the caller's verified bearer token and tenant context, short timeout, and maps invalid/unauthorized/unavailable upstream responses. It persists the verified snapshot in the same Order transaction as the order and Outbox event. Bounded retry/circuit-breaker policy remains future hardening.
3. **Customer Outbox**: add Customer event table, publisher, event schemas and Inbox consumer tests. Publish IDs/versions, not PII.
4. **Contract bounded context — local operational slice implemented**: separate service/database, tenant-isolated contract records, immutable Order snapshots, draft/prepared/awaiting-signature/signed/cancelled lifecycle, audit, transactional Outbox publisher, private PDF storage and DocuSign adapter/webhook exist. Production credentials, provider reconciliation, legal template governance and end-to-end deployment verification remain open. Do not put contract tables in Order or Billing.
5. **Billing enrichment — local implementation complete**: Order exposes its immutable customer billing snapshot to Payment; Payment persists and republishes that snapshot on capture/refund events; Billing stores issuer/recipient snapshots on invoices and credit notes, guards legal issuance, and renders downloadable PDFs only for legally complete issued invoices. The local RabbitMQ smoke test verifies capture, partial refund, exact replay and conflicting-event DLQ behavior. Jurisdiction-aware tax calculation, statutory country-specific fields, accounting exports and archival policy remain production requirements. Never assume PaymentCaptured alone contains legal invoice data.
6. **End-to-end verification**: test tenant isolation, duplicate events, out-of-order delivery, customer archive during order creation, customer update after order creation, payment retry, invoice immutability and DLQ recovery.

## Current implementation status

- Customer Service exists with tenant-scoped profiles, addresses, consent, audit history and idempotent creation.
- Order now requires `customerId` for new orders, validates the active customer through Customer Service using the caller's verified JWT and tenant context, and persists a minimal immutable customer snapshot plus customer version. The migration preserves historical orders with a null customer reference. Customer consent records are intentionally excluded from the order snapshot.
- Billing consumes PaymentCaptured/refund events, preserves the immutable Order/Customer billing snapshot on invoices and credit notes, and exposes tenant-protected invoice PDF downloads. Missing issuer or recipient legal data produces a draft, never a falsely issued document. Configure the real issuer identity/address and jurisdiction-aware tax policy before production.
- Contract Service local slice is implemented: tenant-isolated draft creation from accepted Order snapshots, list/get/history, prepare/cancel, idempotency, audit, RabbitMQ Outbox publishing, immutable PDF artifacts with checksum verification and a real DocuSign adapter with verified webhook handling. Production DocuSign credentials/consent, provider reconciliation, legal template governance and end-to-end deployment verification remain outstanding.

Keep this status section current. Do not describe the target flow as operational until the migrations, adapters, publishers/consumers, authorization and integration tests have landed.
