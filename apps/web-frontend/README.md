# AutoFlow Web Frontend

The AutoFlow frontend is a Nuxt 4 + Vue 3 + Vuetify 3 application. It is delivered as a client-rendered authenticated workspace because Keycloak Authorization Code + PKCE is initialized in the browser.

## Application routes

- `/` — customer-facing vehicle marketplace with responsive navigation, discovery hero, advanced catalogue filters, vehicle details, quote calculation, offer lifecycle and accepted-offer-to-order handoff with active-customer selection. The marketplace uses its own light Vuetify theme; operations and administration remain in the dark workspace shell.
- `/operations/orders` — order management, payment operations, Stripe Payment Element and pricing quotes.
- `/admin` — administration control center with live counts from Catalog, Customer, CRM, Order, Payment, Pricing, Contract, Billing, Fulfillment, Inventory and Reservation APIs, plus refreshable operational shortcuts.
- `/admin/customers` — tenant-scoped customer management with profile CRUD, search, addresses, consent decisions and audit history.
- `/crm/applications` — CRM application pipeline with assignment, eligibility decisions, tasks, interactions, audit history and order conversion.
- `/admin/catalog/manufacturers`, `/admin/catalog/models`, `/admin/catalog/generations`, `/admin/catalog/variants` — resource-specific catalog administration routes with shared CRUD workspace.
- `/operations/orders`, `/operations/payments`, `/operations/pricing` — deep links into order, payment and pricing operations.
- `/operations/services` — cross-service read-only overview for Customers, CRM, Contracts, Billing, Fulfillment, Inventory and Reservations.
- `/operations/order/:id` — dedicated order dossier with lifecycle timeline, audit history, linked payments/reservations/fulfillment/invoices and downloadable offer/contract PDFs.
- `/admin/customer-360` — customer 360 workspace with profile editing, addresses, consents, orders, contracts, audit history and audited Keycloak identity linking.
- `/portal` — self-service profile, editable addresses and consent workspace, restricted to the customer record mapped to the verified Keycloak subject; company legal identity remains advisor-managed.
- `/operations/events` — searchable cross-service activity explorer with source filters, JSON payload inspection, API read errors, Mercure live messages and 30-second refresh.
- `/operations/platform` — central workspace for Analytics KPIs/CSV export, tenant-scoped Audit events, versioned Configuration and Feature Flags, secure Document upload/download, Integration providers/webhooks/delivery queue, vehicle Search, and Workflow/AI/Media service checks. Workflow and Media business APIs are explicitly marked as unavailable until implemented.
- `/operations/assistant` — authenticated frontend entry point to the local Ollama AI chat API. The backend currently has no JWT/tenant enforcement, so keep the service and this proxy on trusted local development networks only. Notification Service remains a background RabbitMQ worker with no REST API.
- `/operations/workflow` — operational workspace for contract creation/preparation/signature documents, inventory registration/status changes, vehicle reservations, fulfillment and delivery transitions, invoice/credit-note details, and audit timelines. Accepted marketplace offers can now be handed over to Order Service with the selected vehicle, quote and active customer. Tabs can be deep-linked with `?tab=contracts`, `?tab=inventory`, `?tab=reservations`, `?tab=fulfillment` or `?tab=billing`.

Nuxt owns the route lifecycle and provides separate layouts for the customer-facing marketplace and the authenticated operations workspace. The marketplace uses reusable vehicle cards, search and filter controls, responsive navigation and a light visual theme. The operations and catalogue administration screens use the authenticated workspace shell. Shared PageHeader and MetricCard components keep administration screens consistent; the catalog editor uses resource-specific routes, Vuetify form controls, searchable data tables, explicit loading/error states and soft-archive actions. Catalogue filtering is delegated to the Vehicle Catalog API (manufacturer, fuel, body style, transmission, power and seats); sorting is applied to the current result page. Vehicle prices and inventory are not fabricated because those concerns do not belong to the Vehicle Catalog Service. The old independent HTML entry points and bootstrap scripts are not used.

## Realtime notifications and event monitoring

The global notification menu requests a short-lived tenant-scoped subscriber cookie from Customer Service after Keycloak authentication. The browser subscribes only to the topic returned by that API; it never supplies a tenant ID or stores a signing key. The RabbitMQ-to-Mercure bridge consumes service event exchanges, validates tenant identifiers and publishes a minimal event summary to a private topic. Raw event payloads and PII are not forwarded. Without the hub, the event monitor continues to refresh service snapshots every 30 seconds.

## End-to-end tests

Run `npm install`, then `npx playwright install chromium`, then `npm run test:e2e`. The suite starts a dedicated Nuxt test server and mocks service APIs to exercise the order dossier and guarded transition, Customer 360 data and Keycloak identity linking, individual and company customer self-service, address create/update/delete, consent updates, and event monitor filtering and payload inspection. The `VITE_E2E_TEST_MODE` authentication bypass is enabled only by the Playwright web server configuration and must never be enabled in a deployed environment.

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
| `/billing/**` | Billing |
| `/contracts/**` | Contract |
| `/fulfillments/**` | Fulfillment |
| `/inventory/**` | Inventory |
| `/reservations/**` | Vehicle Reservation |
| `/audit/**` | API Gateway → Audit Service |
| `/analytics/**` | API Gateway → Analytics Service |
| `/configuration/**` | API Gateway → Configuration Service |
| `/documents/**` | API Gateway → Document Service |
| `/integrations/**` | API Gateway → Integration Service |
| `/search/**` | API Gateway → Search Service |
| `/workflow/**` | Workflow Service health endpoints |
| `/ai/**` | AI Service (local-only; no backend JWT enforcement) |
| `/media/**` | Media Service health endpoints (business API not implemented) |

The proxy forwards the original HTTP method, query string, request body and Authorization header for every service prefix. Customer personal-data responses remain `no-store`; the browser never receives internal service addresses. The Customer Service requires the `autoflow-customer` audience plus `customers:read`, `customers:write` or `customers:manage` as appropriate. Customer 360 is an internal tenant-scoped admin workspace. The `/portal` self-service endpoints enforce a server-side tenant + Keycloak-subject mapping and never trust a customer ID supplied by the browser. CRM actions require the corresponding `crm:applications:*`, `crm:eligibility:manage`, `crm:tasks:*` and `crm:interactions:*` scopes.

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
