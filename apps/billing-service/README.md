# Billing Service

Separate financial-document bounded context. Payment owns Stripe and payment state; Billing owns immutable invoices, VAT breakdowns, credit notes and billing queries.

Consumes PaymentCaptured, PaymentRefunded and PaymentPartiallyRefunded from autoflow.payment.events through queue autoflow.billing.payment-events. The Inbox stores a payload fingerprint: exact redeliveries are ignored, while eventId reuse with different contents is rejected. Malformed or unsupported envelopes are published to autoflow.billing.payment-events.dead before the original delivery is acknowledged; transient database failures are requeued. Invoice numbers are tenant/year scoped (AF-YYYY-000001); credit notes use AC-YYYY-000001.

API (Keycloak billing:read scope and tenant claim):
- GET /api/v1/billing/invoices
- GET /api/v1/billing/invoices/{id}
- GET /api/v1/billing/credit-notes
- GET /api/v1/billing/credit-notes/{id}
- GET /api/v1/billing/summary

BILLING_TAX_RATE_BPS defaults to 1900 (19%). Amounts are treated as VAT-inclusive. This local default is not a multi-country VAT engine. Production invoicing still needs immutable customer billing-address/legal-entity data from Order, jurisdiction-aware tax determination, statutory invoice fields, retention and PDF rendering/signing.
