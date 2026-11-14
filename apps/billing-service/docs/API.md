# Billing Service — API Reference

**Base URL:** http://localhost:8087  
**Authentication:** Keycloak tenant claim and `billing:read` scope.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| GET | `/api/v1/billing/invoices` | billing:read | No query parameters documented. | 200, 401, 403 |
| GET | `/api/v1/billing/invoices/{id}` | billing:read | id path. | 200, 401, 403, 404 |
| GET | `/api/v1/billing/invoices/{id}/document` | billing:read | id path; PDF download only for legally complete issued invoices. Draft invoice returns 409. | 200 (application/pdf), 401, 403, 404, 409 |
| GET | `/api/v1/billing/credit-notes` | billing:read | No query parameters documented. | 200, 401, 403 |
| GET | `/api/v1/billing/credit-notes/{id}` | billing:read | id path. | 200, 401, 403, 404 |
| GET | `/api/v1/billing/summary` | billing:read | No arguments. | 200, 401, 403 |

## Domain behavior
Consumes PaymentCaptured, PaymentRefunded and PaymentPartiallyRefunded events. A captured payment creates an issued invoice only when issuer tax identity/address and recipient billing address are complete; otherwise it creates a draft invoice. Invoice and credit-note documents preserve legal issuer/recipient snapshots. The local VAT default (19%) is not a multi-country VAT engine or legal/tax advice.

## Health
- `GET /health/ready` — readiness.
