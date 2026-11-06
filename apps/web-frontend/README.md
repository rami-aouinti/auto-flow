# AutoFlow Web Frontend

Vue 3 + TypeScript client for Vehicle Catalog, Offer, Pricing, Order Management and Payment services.

## Run locally

From the repository root:

```bash
docker compose up -d --build vehicle-catalog-service offer-service pricing-service order-service payment-service keycloak web-frontend
```

Open http://localhost:3000 and sign in with the local-only Keycloak account configured for the required service scopes. The existing demo account may not have every Order and Payment permission; the API remains authoritative and returns 403 when a role/scope is missing.

The SPA uses Keycloak Authorization Code + PKCE (S256), keeps the access token in memory, attaches it to same-origin API requests, and refreshes it before expiry. Vite proxies the following prefixes to internal Compose DNS names:

| Browser prefix | Service | Local API prefix |
|---|---|---|
| `/catalog` | Vehicle Catalog | `/api/v1` |
| `/offers` | Offer | `/api` |
| `/pricing` | Pricing | `/api/pricing` |
| `/orders` | Order Management | `/api/v1/orders` |
| `/payments` | Payment | `/api/v1/payments` |

## Frontend capabilities

- Vehicle catalogue search, filters, details and manufacturer lookup.
- Offer creation, quote calculation, lifecycle actions, history and PDF download.
- Order creation from Offer/Quote/Vehicle references; Order Management resolves and verifies the authoritative snapshots itself.
- Tenant-scoped order list, detail, audit history and lifecycle transitions (submit, review, accept, reject, cancel, contract pending, complete).
- Payment Intent creation for an accepted order, payment retrieval, capture, cancel and partial/full refund actions.
- Pricing quote listing and deletion.

Every order/payment mutation sends a fresh `Idempotency-Key`. The browser never supplies order payable amount or trusted snapshots; those are derived and validated by the backend services. Permissions and tenant ownership are enforced by the APIs, not by hiding buttons in the UI.

## Payment checkout boundary

The Payment API creates a Stripe PaymentIntent and returns a client secret. This frontend currently creates and manages the Payment record, but does **not** collect card details or confirm the PaymentIntent. Completing card checkout requires Stripe.js Elements and a publishable key supplied through the deployment environment. Never place a Stripe secret key in a `VITE_*` variable or browser bundle.

The Payment service currently exposes payment retrieval by Payment UUID but no tenant-scoped payment-list endpoint. The UI therefore shows the Payment returned by the create action or retrieved by its UUID; it does not pretend to provide a complete payment history.

## Configuration and deployment

Copy `.env.example` to your local frontend environment when running Vite outside Compose. The API base variables default to same-origin prefixes. Proxy targets are only used by the local Vite development server.

This Vite server is for local development only. Production should serve the built static assets behind TLS and route the five API prefixes through the authenticated ingress/reverse proxy. Do not expose internal service ports directly to the public internet.


## Stripe Checkout and Payment History

Set `VITE_STRIPE_PUBLISHABLE_KEY` to a Stripe **publishable** key (`pk_test_...` locally or `pk_live_...` in production). The operations page loads Stripe.js and mounts the Payment Element using the PaymentIntent client secret returned by Payment Service. Card data is submitted directly to Stripe; it is never sent to or stored by AutoFlow. The PaymentIntent uses manual capture, so a confirmed payment may become `authorized` and still requires the existing capture operation. Configure the Stripe webhook endpoint and signing secret on Payment Service for authoritative asynchronous status updates. Never expose `STRIPE_SECRET_KEY` in a `VITE_*` variable.

Payment History is served by `GET /api/v1/payments?limit=20&offset=0` (optional `orderId` and `status` filters). The endpoint requires `payments:read`, scopes every query to the authenticated tenant, applies PostgreSQL row-level security, and omits the client secret from history records.

## Catalogue Administration

Open `http://localhost:3000/admin-catalog.html` and sign in with a Keycloak principal that has `catalog:manage`. The page supports creating, listing, editing and archiving manufacturers, models, generations and vehicle variants. The imported local Keycloak user `catalog-admin` has the `catalog:manage` scope. The `order-operator` user has the `payments:read`, `payments:write`, `payments:capture` and `payments:refund` roles for the operations page. Creation uses the catalogue's existing registration endpoints; administration uses `/api/v1/admin/catalog/{resource}`. Archive is intentionally a soft-delete so historical offer/order snapshots and references remain valid. The catalogue API remains the authorization boundary.
