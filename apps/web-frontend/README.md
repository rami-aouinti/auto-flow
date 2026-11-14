# AutoFlow Web Frontend

The AutoFlow frontend is a Nuxt 4 + Vue 3 + Vuetify 3 application. It is delivered as a client-rendered authenticated workspace because Keycloak Authorization Code + PKCE is initialized in the browser.

## Application routes

- `/` — customer-facing vehicle marketplace with responsive navigation, discovery hero, advanced catalogue filters, vehicle details, quote calculation and offer lifecycle. The marketplace uses its own light Vuetify theme; operations and administration remain in the dark workspace shell.
- `/operations/orders` — order management, payment operations, Stripe Payment Element and pricing quotes.
- `/admin` — administration control center with live counts from Catalog, Customer, Order, Payment and Pricing APIs.
- `/admin/customers` — tenant-scoped customer management with profile CRUD, search, addresses, consent decisions and audit history.
- `/admin/catalog/manufacturers`, `/admin/catalog/models`, `/admin/catalog/generations`, `/admin/catalog/variants` — resource-specific catalog administration routes with shared CRUD workspace.
- `/operations/orders`, `/operations/payments`, `/operations/pricing` — deep links into order, payment and pricing operations.
- `/operations/services` — cross-service read-only overview for Customers, CRM, Contracts, Billing, Fulfillment, Inventory and Reservations.

Nuxt owns the route lifecycle and provides separate layouts for the customer-facing marketplace and the authenticated operations workspace. The marketplace uses reusable vehicle cards, search and filter controls, responsive navigation and a light visual theme. The operations and catalogue administration screens use the authenticated workspace shell. Shared PageHeader and MetricCard components keep administration screens consistent; the catalog editor uses resource-specific routes, Vuetify form controls, searchable data tables, explicit loading/error states and soft-archive actions. Catalogue filtering is delegated to the Vehicle Catalog API (manufacturer, fuel, body style, transmission, power and seats); sorting is applied to the current result page. Vehicle prices and inventory are not fabricated because those concerns do not belong to the Vehicle Catalog Service. The old independent HTML entry points and bootstrap scripts are not used.

## Authentication

The client-only Keycloak plugin uses Authorization Code Flow with PKCE S256. It requires a valid login before the application is mounted and refreshes the access token before expiry. The Axios API client attaches the bearer token to every service request. Backend authorization remains authoritative. Catalogue writes require `catalog:manage`; customer operations require the corresponding `customers:*` scopes; order transitions and payments use their own scopes. The demo realm user is granted the local development roles for these workflows in the realm export. Existing persisted Keycloak realms do not automatically re-import changed users, roles or clients: apply the added roles to the existing `demo` user and add the `autoflow-payment` bearer-only client plus the `payment-api-audience` mapper on `autoflow-local-client` in the Keycloak Admin Console, or recreate only the development realm after backing up data.

## Service integration

Nuxt server routes proxy the same-origin prefixes to the internal Compose service DNS names:

| Browser prefix | Upstream |
| --- | --- |
| `/catalog/**` | Vehicle Catalog |
| `/customers/**` | Customer Service |
| `/crm/**` | CRM Service |
| `/offers/**` | Offer |
| `/pricing/**` | Pricing |
| `/orders/**` | Order |
| `/payments/**` | Payment |

The proxy forwards the original HTTP method, query string, request body and Authorization header. Customer personal-data responses remain `no-store`; the browser never receives internal service addresses. The Customer Service requires the `autoflow-customer` audience plus `customers:read`, `customers:write` or `customers:manage` as appropriate.

## Local development

From the repository root:

```bash
docker compose --env-file .env.example up -d web-frontend
```

Open [http://localhost:3000](http://localhost:3000). The Compose service installs the Node dependencies and starts the Nuxt development server. Node 22.12 or newer is required.

## Stripe

Set `VITE_STRIPE_PUBLISHABLE_KEY` in the root Compose environment. The browser receives only the Stripe publishable key. `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` remain server-side in Payment Service. Card data is collected by Stripe Elements and is never sent to AutoFlow.

## Production

Build with `npm run build` and deploy the generated Nuxt/Nitro output behind TLS. Configure the service proxy target environment variables for the internal service network. Keep Keycloak issuer, client origins, allowed redirect URIs and web origins aligned with the public frontend origin.
