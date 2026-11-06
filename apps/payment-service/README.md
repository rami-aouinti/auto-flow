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

## Local Stripe test setup

1. Put a Stripe test-mode secret key in `.env` as `STRIPE_SECRET_KEY=sk_test_...`.
2. Start Stripe CLI forwarding: `stripe listen --forward-to localhost:8086/api/v1/payments/webhooks/stripe`.
3. Copy the CLI signing secret (`whsec_...`) to `STRIPE_WEBHOOK_SECRET`.
4. Recreate the services: `docker compose --env-file .env up -d --build payment-service payment-outbox-publisher`.

Subscribe to these PaymentIntent events in the Stripe webhook endpoint:
- `payment_intent.amount_capturable_updated`
- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `payment_intent.canceled`

For an existing PostgreSQL volume, Docker init scripts do not rerun automatically. Apply the two mounted SQL files once:
```bash
docker compose exec -T postgres psql -U autoflow -d autoflow -v ON_ERROR_STOP=1 -f /docker-entrypoint-initdb.d/150-payment-role.sql
docker compose exec -T postgres psql -U autoflow -d autoflow -v ON_ERROR_STOP=1 -f /docker-entrypoint-initdb.d/160-payment-schema.sql
```

The payment outbox publishes durable events to the `autoflow.payment.events` topic exchange. Publication retries use exponential backoff and stop after ten attempts for manual inspection.
