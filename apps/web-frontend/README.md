# AutoFlow Web Frontend

Vue 3 + TypeScript client for Vehicle Catalog, Offer and Pricing.

Run from repository root: `docker compose up -d --build vehicle-catalog-service offer-service pricing-service keycloak web-frontend`, then open http://localhost:3000 and sign in with local-only `demo / demo`.

Uses Keycloak Authorization Code + PKCE (S256). The browser calls the catalogue list/detail/manufacturer endpoints, sends quote calculations to Offer, and Offer exchanges the user token before calling Pricing. Vite proxies `/catalog`, `/offers` and `/pricing` to the internal Compose service DNS names, so no permissive API CORS policy is required.

The catalogue owns vehicle identity and technical specifications, not prices. The quote form therefore requires an explicit vehicle price, down payment and residual value; no commercial price is fabricated. The Pricing API remains tenant- and subject-scoped.

This Vite server is for local development only. Production should serve the built static assets behind TLS and route the three API prefixes through an ingress/reverse proxy.
