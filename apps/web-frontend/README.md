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
