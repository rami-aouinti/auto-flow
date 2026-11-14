# Billing Service — API Reference

**Base URL:** http://localhost:8087  
**Authentication:** Keycloak tenant claim and `billing:read` scope. PDF endpoints additionally require the `autoflow-document` audience and `documents:write` on first generation / `documents:read` on retrieval.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| GET | `/api/v1/billing/invoices` | billing:read | No query parameters documented. | 200, 401, 403 |
| GET | `/api/v1/billing/invoices/{id}` | billing:read | id path. | 200, 401, 403, 404 |
| GET | `/api/v1/billing/invoices/{id}/document` | billing:read | id path; PDF download only for legally complete issued invoices. Draft invoice returns 409. | 200 (application/pdf), 401, 403, 404, 409 |
| GET | `/api/v1/billing/credit-notes` | billing:read | No query parameters documented. | 200, 401, 403 |
| GET | `/api/v1/billing/credit-notes/{id}` | billing:read | id path. | 200, 401, 403, 404 |
| GET | `/api/v1/billing/credit-notes/{id}/document` | billing:read + documents:write/read | Lazy-generate a Gutschrift PDF from its immutable snapshot, upload to Document Service and proxy canonical bytes with SHA-256 verification. | 200 (application/pdf), 401, 403, 404, 409, 503 |
| GET | `/api/v1/billing/summary` | billing:read | No arguments. | 200, 401, 403 |

## Document storage

Billing owns invoice/credit-note facts and immutable legal snapshots. Document Service owns canonical PDF bytes, malware scanning, encrypted object storage, versioning and retention. Billing persists only tenant-scoped Document UUID/checksum references in `billing_document_references`. Repeated retrieval reuses the reference and verifies the downloaded bytes. Run `bin/smoke-test-document-integration.php` with `DOCUMENT_E2E_TOKEN`, `BILLING_E2E_INVOICE_ID`, and optionally `BILLING_E2E_CREDIT_NOTE_ID`.

## Domain behavior
Consumes PaymentCaptured, PaymentRefunded and PaymentPartiallyRefunded events. A captured payment creates an issued invoice only when issuer tax identity/address and recipient billing address are complete; otherwise it creates a draft invoice. Invoice and credit-note documents preserve legal issuer/recipient snapshots. The local VAT default (19%) is not a multi-country VAT engine or legal/tax advice.

## Health
- `GET /health/ready` — readiness.
