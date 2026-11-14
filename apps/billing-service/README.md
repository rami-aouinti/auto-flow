# Billing Service

Separate financial-document bounded context. Payment owns Stripe and payment state; Billing owns immutable invoices, VAT breakdowns, credit notes and billing queries.

Consumes PaymentCaptured, PaymentRefunded and PaymentPartiallyRefunded from autoflow.payment.events through queue autoflow.billing.payment-events. The Inbox stores a payload fingerprint: exact redeliveries are ignored, while eventId reuse with different contents is rejected. Malformed or unsupported envelopes are published to autoflow.billing.payment-events.dead before the original delivery is acknowledged; transient database failures are requeued. Invoice numbers are tenant/year scoped (AF-YYYY-000001); credit notes use AC-YYYY-000001. Payment creation carries the immutable Customer billing snapshot through Order and Payment. Invoices and credit notes retain issuer/recipient legal snapshots; a captured payment creates an `issued` invoice only when both issuer tax identity/address and recipient billing address are complete. Otherwise it creates a `draft` invoice rather than falsely claiming a legally complete invoice.

API (Keycloak billing:read scope and tenant claim):
- GET /api/v1/billing/invoices
- GET /api/v1/billing/invoices/{id}
- GET /api/v1/billing/invoices/{id}/document — downloadable PDF for legally complete issued invoices (drafts return 409)
- GET /api/v1/billing/credit-notes
- GET /api/v1/billing/credit-notes/{id}
- GET /api/v1/billing/summary

BILLING_TAX_RATE_BPS defaults to 1900 (19%). Amounts are treated as VAT-inclusive. This local default is not a multi-country VAT engine. Configure `BILLING_ISSUER_LEGAL_NAME`, `BILLING_ISSUER_TAX_NUMBER` or `BILLING_ISSUER_VAT_ID`, and `BILLING_ISSUER_ADDRESS_JSON` with the actual legal entity details before production. The PDF renderer uses the immutable issuer/recipient snapshot and refuses to render drafts. Jurisdiction-aware tax determination, country-specific statutory invoice fields, archival/retention, accounting exports and legal review remain production requirements; the local 19% rate is not legal/tax advice.
