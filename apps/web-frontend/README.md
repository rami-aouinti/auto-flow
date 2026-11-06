# AutoFlow Web Frontend

Vue 3 + TypeScript client for Vehicle Catalog, Offer and Pricing.

Run from repository root: `docker compose up -d --build vehicle-catalog-service offer-service pricing-service keycloak web-frontend`, then open http://localhost:3000 and sign in with local-only `demo / demo`.

Uses Keycloak Authorization Code + PKCE (S256). The browser calls the catalogue list/detail/manufacturer endpoints, sends quote calculations to Offer, and Offer exchanges the user token before calling Pricing. Vite proxies `/catalog`, `/offers` and `/pricing` to the internal Compose service DNS names, so no permissive API CORS policy is required.

The catalogue owns vehicle identity and technical specifications, not prices. The quote form therefore requires an explicit vehicle price, down payment and residual value; no commercial price is fabricated. The Pricing API remains tenant- and subject-scoped.

This Vite server is for local development only. Production should serve the built static assets behind TLS and route the three API prefixes through an ingress/reverse proxy.


## Operations and payments

The operations dashboard is available at `http://localhost:3000/operations.html`. It uses the existing Order Management, Payment and Pricing APIs through the same-origin Vite proxies. Payment history uses the current tenant-scoped `GET /api/v1/payments?limit=20&offset=0` endpoint already provided by Payment Service; no duplicate backend handler is added here.

For Stripe Checkout, set `VITE_STRIPE_PUBLISHABLE_KEY` in the root Compose `.env` file. Payment Service must receive `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` server-side. Only the publishable key is exposed to the browser. The Payment Element submits card details directly to Stripe; AutoFlow does not receive or store card numbers or CVC values. Configure Stripe webhooks for authoritative asynchronous payment status updates.

## Catalogue administration

The catalogue administration page is available at `http://localhost:3000/admin-catalog.html`. It supports create, list, edit and soft-archive operations for manufacturers, models, generations and variants. The backend routes are under `/api/v1/admin/catalog/{resource}` and require the Keycloak `catalog:manage` scope. New records are created as drafts. Archive preserves stable identifiers for historical offer and order references.
