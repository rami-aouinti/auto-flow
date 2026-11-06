# AutoFlow Payment Service

Symfony 7.4 / PHP 8.4 bounded context for payment lifecycle management. Stripe is the initial Payment Provider.

## Responsibilities
- Create Stripe PaymentIntents with manual capture (authorization before capture).
- Capture, cancel and refund payments.
- Verify Stripe webhook signatures and deduplicate provider event IDs.
- Enforce tenant row-level security and actor-scoped idempotency.
- Persist payment changes and domain events in PostgreSQL.
- Never store PAN, CVC, expiry or raw card data. Stripe.js/Payment Element handles payment details.

## Order integration boundary
Payment accepts only an order ID from the caller. Before contacting Stripe it calls Order Service over the private network, authenticates with `X-Autoflow-Service-Token`, verifies the tenant and requires the order status to be `accepted`. Order derives `amountMinor` and `currency` from its immutable quote snapshot; request-supplied amounts are ignored. Configure `ORDER_PAYMENT_CONTEXT_URL` and the same high-entropy `ORDER_PAYMENT_SHARED_SECRET` in both services. The local default in Compose is for development only.

## Configuration
Set PAYMENT_DATABASE_URL, STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET, AUTOFLOW_JWT_ISSUER and AUTOFLOW_JWT_JWKS_URI. Use Stripe test keys locally; never commit live credentials.

## API
- POST /api/v1/payments (scope payments:write; body contains orderId only; Idempotency-Key required)
- GET /api/v1/payments/{paymentId} (scope payments:read)
- POST /api/v1/payments/{paymentId}/capture (scope payments:capture; Idempotency-Key required)
- POST /api/v1/payments/{paymentId}/cancel (scope payments:write; Idempotency-Key required)
- POST /api/v1/payments/{paymentId}/refund (scope payments:refund; Idempotency-Key required)
- POST /api/v1/payments/webhooks/stripe (public route excluded from JWT; Stripe signature required)

Amounts are integers in minor units (e.g. EUR 1299 = €12.99) and are resolved by Order from its quote snapshot. Payment publishes versioned PaymentAuthorized, PaymentCaptured, PaymentFailed, PaymentRefunded and PaymentCancelled integration events through its transactional Outbox.

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

Compose now runs the one-shot `postgres-bootstrap` service after PostgreSQL is healthy and before the Order/Payment APIs and workers start. It reapplies the idempotent Order and Payment role/schema migrations on both fresh and existing PostgreSQL volumes, and reconciles the local runtime/publisher role passwords with the connection URLs in `.env.example`.

To rerun the bootstrap explicitly after changing one of these SQL files:
```bash
docker compose --env-file .env.example run --rm postgres-bootstrap
```

This operation does not delete or recreate the PostgreSQL volume.

The payment outbox publishes durable events to the `autoflow.payment.events` topic exchange. Publication retries use exponential backoff and stop after ten attempts for manual inspection.
