# AutoFlow Payment Service

Symfony 7.4 / PHP 8.4 bounded context for payment lifecycle management. Stripe is the initial Payment Provider.

## Responsibilities
- Create Stripe PaymentIntents with manual capture (authorization before capture).
- Capture, cancel and refund payments.
- Verify Stripe webhook signatures and deduplicate provider event IDs.
- Enforce tenant row-level security and actor-scoped idempotency.
- Persist payment changes and domain events in PostgreSQL.
- Never store PAN, CVC, expiry or raw card data. Stripe.js/Payment Element handles payment details.

## Important integration boundary
The create endpoint accepts an order ID, amount in minor units and ISO currency for the current integration stage. Before production, replace the amount input with a server-to-server lookup of the authoritative order/quote total and verify the order is accepted and payable. Never trust a browser-provided amount in production.

## Configuration
Set PAYMENT_DATABASE_URL, STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET, AUTOFLOW_JWT_ISSUER and AUTOFLOW_JWT_JWKS_URI. Use Stripe test keys locally; never commit live credentials.

## API
- POST /api/v1/payments (scope payments:write; Idempotency-Key required)
- GET /api/v1/payments/{paymentId} (scope payments:read)
- POST /api/v1/payments/{paymentId}/capture (scope payments:capture; Idempotency-Key required)
- POST /api/v1/payments/{paymentId}/cancel (scope payments:write; Idempotency-Key required)
- POST /api/v1/payments/{paymentId}/refund (scope payments:refund; Idempotency-Key required)
- POST /api/v1/payments/webhooks/stripe (public route excluded from JWT; Stripe signature required)

Amounts are integers in minor units (e.g. EUR 1299 = €12.99). Capture and refunds are idempotent at both AutoFlow and Stripe layers.
