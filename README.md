# AutoFlow Mobility Platform

Enterprise mobility and vehicle-leasing portfolio project built with Symfony microservices.

> Independent learning project inspired by a digital vehicle-leasing journey. It does not
> describe MeinAuto.de's internal architecture.

## Stack

- Traefik v3 API Gateway for centralized HTTP routing, CORS, security headers, per-IP rate limits and Prometheus metrics
- PHP 8.4 CLI and Xdebug 3
- Supervisor-managed Offer background processes in Docker Compose
- Temporal CLI development server with persistent local SQLite state
- RoadRunner 3.0.1 HTTP and gRPC workers for the Offer and Pricing services
- Symfony 7.4 microservices with independent Composer projects (Customer, Offer, Pricing, Vehicle Catalog, Order, Payment, Billing and Contract)
- OpenTelemetry PHP extension, SDK and Symfony auto-instrumentation
- PostgreSQL 16, RabbitMQ 4, Redis 7, Elasticsearch 8 and Kibana
- Mercure Hub for real-time Server-Sent Events
- Prometheus and Grafana for metrics and dashboards
- Loki and Grafana Alloy for centralized container logs
- Tempo and OTLP for distributed traces
- PostgreSQL, Redis, Elasticsearch, RabbitMQ and container metrics exporters
- Mailpit for local email testing
- Adminer 5 for local PostgreSQL inspection
- Keycloak OIDC with RS256 JWT access tokens for local authentication
- NelmioApiDocBundle + Swagger UI / OpenAPI 3 for interactive REST API testing in development only
- PHP-CS-Fixer, PHP_CodeSniffer, PHPStan, PHPMD, Rector and PHPUnit
- GitHub Actions quality workflow

## Start locally (WSL2)

Docker Desktop with WSL2 integration is required. Local PHP and Composer are not required.
The complete observability stack is resource-intensive; allocate at least 8 GB RAM to Docker Desktop.

~~~bash
cp .env.example .env
# Replace the local-only JWT and Grafana passwords in .env before use.
docker compose --env-file .env.example up -d --build
docker compose --env-file .env.example ps
docker compose --env-file .env.example exec php composer install
docker compose --env-file .env.example exec php php -v
docker compose --env-file .env.example up -d --build vehicle-catalog-service vehicle-catalog-outbox-publisher order-service order-outbox-publisher payment-service payment-outbox-publisher order-payment-event-consumer order-workflow-worker customer-service contract-service
~~~

The first local build installs each service's independent Composer dependencies. Offer, Pricing and Vehicle Catalog provide development-only Swagger UI; Order Management publishes a checked-in OpenAPI YAML contract. Production builds keep Composer's default `--no-dev` behavior. Use `make api-docs` for the three generated contracts and see `apps/order-service/docs/openapi.yaml` for Order.
Subsequent starts reuse the built images unless their source or Dockerfile changes.

## Local interfaces and endpoints

Use **http://localhost:8088** as the single HTTP entry point for application clients. The gateway preserves the service-owned `/api/...` paths and routes them to the corresponding Symfony application. Direct backend ports remain published only for local diagnostics and service-specific Swagger UI.

| Service | URL | Purpose |
| --- | --- | --- |
| API Gateway (Traefik) | http://localhost:8088 | Single HTTP entry point; routes Catalog, Offers, Pricing, Orders, Payments and Billing; applies CORS, security headers, in-memory IP rate limiting and in-flight request limits |
| Billing Service | http://localhost:8087 | Invoices, VAT breakdowns, credit notes and billing summary |
| Customer Service | http://localhost:8089 | Tenant-scoped individual/company profiles, addresses, consents and audit history |
| Contract Service | http://localhost:8090 | Tenant-scoped contract drafts, immutable legal snapshots and lifecycle audit |
| Offer Service | http://localhost:8081 | Demo offer API, calls Pricing Service |
| Offer Swagger UI | http://localhost:8081/api/doc | Interactive Offer REST API documentation (dev only) |
| Offer OpenAPI JSON | http://localhost:8081/api/doc.json | Machine-readable Offer API contract (dev only) |
| Pricing Service | http://localhost:8082 | Tenant-scoped quote calculation API |
| Vehicle Catalog Service | http://localhost:8084 | Authoritative manufacturers, models, generations and vehicle variants |
| Order Management Service | http://localhost:8085 | Tenant-scoped order lifecycle, audit history, immutable commercial snapshots and payment state |
| Payment Service | http://localhost:8086 | Stripe PaymentIntents, capture/refund lifecycle and payment events |
| Pricing Swagger UI | http://localhost:8082/api/doc | Interactive Pricing REST API documentation (dev only) |
| Pricing OpenAPI JSON | http://localhost:8082/api/doc.json | Machine-readable Pricing API contract (dev only) |
| Vehicle Catalog Swagger UI | http://localhost:8084/api/doc | Interactive Vehicle Catalog REST API documentation (dev only) |
| Vehicle Catalog OpenAPI JSON | http://localhost:8084/api/doc.json | Machine-readable Vehicle Catalog API contract (dev only) |
| Offer Service gRPC | localhost:9001 | Internal gRPC listener (platform Ping smoke check) |
| Pricing Service gRPC | localhost:9002 | `PricingService.CalculateQuote` plus platform Ping |
| RabbitMQ Management | http://localhost:15672 | Exchanges, queues, consumers, message rates and connections |
| Elasticsearch | http://localhost:9200 | Search and indexing API |
| Kibana | http://localhost:5601 | Elasticsearch indices, documents, queries and dashboards |
| Mercure Hub | http://localhost:3001/.well-known/mercure | Real-time SSE hub |
| Grafana | http://localhost:3000 | Central dashboards (credentials from .env) |
| Prometheus | http://localhost:9090 | Metrics, targets and PromQL |
| Loki | http://localhost:3100 | Log aggregation API (explore through Grafana) |
| Tempo | http://localhost:3200 | Distributed trace backend (explore through Grafana) |
| Alloy | http://localhost:12345 | Log collector status and component UI |
| Mailpit | http://localhost:8025 | Local email inbox |
| Adminer | http://localhost:8083 | Browser-based PostgreSQL administration (local host only) |
| Keycloak | http://localhost:8080 | Local OIDC identity provider and token issuer |
| PostgreSQL | localhost:5432 | Relational database |
| Temporal gRPC | localhost:7233 | Local Temporal development server |
| Temporal UI | http://localhost:8233 | Inspect Order lifecycle workflow executions |
| Redis | localhost:6379 | Cache and ephemeral data |
| OTLP gRPC / HTTP | localhost:4317 / 4318 | OpenTelemetry trace ingestion |

## Billing Service

Billing consumes PaymentCaptured and refund events, creates tenant-scoped invoices and credit notes, and exposes read APIs. Payment remains the only Stripe integration. The local VAT default is 19%; jurisdiction-aware tax calculation and legally compliant invoice rendering remain production requirements. See [Billing Service documentation](apps/billing-service/README.md).

## Customer Service

Customer owns customer profiles, contact details, addresses, consent decisions and audit history. It enforces tenant isolation in PostgreSQL and exposes a protected API at `/api/v1/customers/**`. See [Customer Service documentation](apps/customer-service/README.md).

## Contract Service

Contract owns contract drafts and their immutable legal snapshots, referencing accepted orders without cross-service database joins. The first slice includes tenant-isolated storage, idempotent creation, prepare/cancel transitions, audit history and transactional Outbox writes. PDF rendering, Outbox publishing and real e-signature integration are still open. See [Contract Service documentation](apps/contract-service/README.md).

## API Gateway routes

| Public path | Upstream service | Notes |
| --- | --- | --- |
| `/api/v1/manufacturers/**`, `/api/v1/vehicles/**`, `/api/v1/admin/catalog/**` | Vehicle Catalog (`vehicle-catalog-service:8080`) | Public catalogue reads and admin catalogue management |
| `/api/offers/**` | Offer (`offer-service:8080`) | Offer lifecycle and quote composition |
| `/api/pricing/**` | Pricing (`pricing-service:8080`) | Tenant-scoped quote calculation and retrieval |
| `/api/v1/orders/**` | Order (`order-service:8080`) | Versioned order lifecycle API |
| `/api/v1/payments/**` | Payment (`payment-service:8080`) | Payment intent, payment lifecycle and Stripe webhook API |
| `/api/v1/billing/**` | Billing (`billing-service:8080`) | Tenant invoices, VAT breakdowns, credit notes and summary |
| `/api/v1/customers/**` | Customer (`customer-service:8080`) | Tenant customer profiles, addresses, consents and audit history |
| `/api/v1/contracts/**` | Contract (`contract-service:8080`) | Contract drafts, legal snapshots, lifecycle and audit history |

The gateway routes `/api/v1/contracts/**` to Contract Service at `contract-service:8080`. The gateway does **not** replace authentication or authorization. It does not decode or trust JWT claims: every backend continues to validate the Keycloak RS256 token, issuer, audience, tenant and service-owned scopes. The local edge rate limiter is in-memory and per gateway replica; distributed per-tenant quotas backed by Redis remain a separate production hardening task. TLS termination and trusted-proxy configuration must be completed for the target deployment.

## Authentication quick start

Customer, Offer, Pricing, Vehicle Catalog, Order Management, Billing and Contract validate RS256 access tokens locally using Keycloak's JWKS endpoint. Browser requests pass through Traefik at `http://localhost:8088`; direct backend ports are retained for local diagnostics. Their expected audiences are distinct (`autoflow-offer`, `autoflow-pricing`, `autoflow-catalog`, `autoflow-order`, `autoflow-billing`, `autoflow-customer` and `autoflow-contract`). The `/health` and development-only Swagger endpoints remain public; `/api/*` requires a bearer token.

The imported `demo` user/password `demo`, `order-operator` user/password `order-operator`, and the local password-grant client below are for local testing only. Do not enable the password grant or reuse these credentials in a shared or production environment. Real browser clients should use Authorization Code + PKCE.

~~~bash
TOKEN=$(curl -fsS -X POST http://localhost:8080/realms/autoflow/protocol/openid-connect/token \\
  -H 'Content-Type: application/x-www-form-urlencoded' \\
  --data-urlencode 'grant_type=password' \\
  --data-urlencode 'client_id=autoflow-local-client' \\
  --data-urlencode 'username=demo' \\
  --data-urlencode 'password=demo' | jq -r .access_token)

curl -i http://localhost:8081/api/offers/demo-vehicle-001 \\
  -H "Authorization: Bearer $TOKEN"

# Create a quote through Offer; the downstream Pricing request uses a Keycloak-exchanged token.
curl -i -X POST http://localhost:8081/api/offers/vehicle-001/quotes \\
  -H "Authorization: Bearer $TOKEN" \\
  -H 'Content-Type: application/json' \\
  -d '{"vehiclePrice":"30000.00","downPayment":"3000.00","residualValue":"9000.00","currency":"EUR","durationMonths":36,"annualInterestRateBasisPoints":500}'

curl -i http://localhost:8082/api/pricing/quotes \\
  -H "Authorization: Bearer $TOKEN" \\
  -H 'Content-Type: application/json' \\
  -d '{"vehicleId":"vehicle-001","vehiclePrice":"30000.00","downPayment":"3000.00","residualValue":"9000.00","currency":"EUR","durationMonths":36,"annualInterestRateBasisPoints":500}'
~~~

~~~bash
# Order Management demo token (order-operator has read/write/manage permissions).
ORDER_TOKEN=$(curl -fsS -X POST http://localhost:8080/realms/autoflow/protocol/openid-connect/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=password' \
  --data-urlencode 'client_id=autoflow-local-client' \
  --data-urlencode 'username=order-operator' \
  --data-urlencode 'password=order-operator' | jq -r .access_token)

curl -i -X POST http://localhost:8085/api/v1/orders \
  -H "Authorization: Bearer $ORDER_TOKEN" \
  -H 'Idempotency-Key: local-order-demo-001' \
  -H 'Content-Type: application/json' \
  -d '{"offerId":"offer-123","quoteId":"quote-456","vehicleId":"variant-789","offerSnapshot":{"offerVersion":1},"quoteSnapshot":{"currency":"EUR","monthlyAmount":"329.00","durationMonths":48},"vehicleSnapshot":{"manufacturer":"Example Motors","model":"E-1"}}'
~~~

Offer validates the incoming user access token, then exchanges it with Keycloak using the confidential `autoflow-offer-to-pricing` client and the standard OAuth 2.0 Token Exchange grant. The exchanged token is downscoped to the `autoflow-pricing` audience and sent to Pricing. Pricing therefore validates its own audience while retaining the user's `sub`; quote ownership is based on that verified subject. The local realm gives the demo user the `offers:read`, `offers:quote` and `pricing:quote` realm roles, assigns the `tenant-demo` tenant attribute, and includes the Offer workload client in the original token audience. Both the original and exchanged token receive the `tenant_id` claim from the user's Keycloak attribute. For a production realm, enable Standard Token Exchange on the confidential requester client, allow that client to exchange to the Pricing audience, and map only the appropriate user roles/permissions. Do not use the deprecated legacy token-exchange feature.

## Order and Payment distributed lifecycle

Payment creation accepts an `orderId` only. Payment Service asks Order Service for the accepted order's payable context over the private Docker network; Order verifies the tenant, requires `status=accepted`, and derives amount/currency from its immutable quote snapshot. Configure the same high-entropy `ORDER_PAYMENT_SHARED_SECRET` in both services (the checked-in `.env.example` value is development-only).

Payment commits lifecycle events to its transactional Outbox. The publisher sends versioned events to the durable `autoflow.payment.events` exchange. The `order-payment-event-consumer` uses a tenant-scoped inbox for deduplication, updates `orders.payment_status`, and writes corresponding `OrderPayment*` events to the Order Outbox. The Temporal worker consumes those events: `OrderPaymentAuthorized` advances the accepted order into contract preparation; capture is recorded while fulfilment remains a separate business decision; failure raises a payment SLA alert; refund/cancellation ends the active lifecycle.

For an existing PostgreSQL volume, apply the new Order payment lifecycle migration once:
```bash
docker compose exec -T postgres psql -U autoflow -d autoflow -v ON_ERROR_STOP=1 -f /docker-entrypoint-initdb.d/145-order-payment-lifecycle.sql
```
Then rebuild/start the integrated services:
```bash
docker compose --env-file .env up -d --build order-service payment-service payment-outbox-publisher order-payment-event-consumer order-outbox-publisher order-workflow-worker
```

## CoreBundle quick check

Every HTTP request handled by Offer or Pricing receives a validated or generated `X-Correlation-ID`. The ID is returned in the response and propagated by the Symfony HttpClient decorator to downstream HTTP calls.

Check the internal gRPC transport smoke method (requires `grpcurl`):

~~~bash
grpcurl -plaintext \
  -import-path packages/core-bundle/proto \
  -proto core/v1/health.proto \
  localhost:9002 autoflow.core.v1.PlatformHealthService/Ping
~~~

This Ping verifies the gRPC listener and worker registration. The Pricing service also exposes a versioned business RPC, shown below. Offer business RPCs and the Offer → Pricing gRPC client are still pending.

Call the Pricing business RPC (same Application handler as the REST endpoint):

~~~bash
grpcurl -plaintext \
  -import-path packages/core-bundle/proto \
  -import-path apps/pricing-service/proto \
  -proto core/v1/health.proto \
  -proto pricing/v1/pricing.proto \
  -d '{"vehicleId":"vehicle-001","vehiclePrice":"30000.00","downPayment":"3000.00","residualValue":"9000.00","currency":"EUR","durationMonths":36,"annualInterestRateBasisPoints":500}' \
  localhost:9002 autoflow.pricing.v1.PricingService/CalculateQuote
~~~

The gRPC adapter maps the Protobuf request into `CalculateQuoteCommand` and delegates to the same `CalculateQuoteHandler` as REST. Money values stay decimal strings; no floating-point conversion is introduced.

The bundle also adds:
- `GET /health/live` — liveness endpoint.
- `GET /health/ready` — readiness endpoint aggregating registered health checks; it fails closed (503) when no checks are registered.
- RoadRunner HTTP workers that bridge PSR-7 requests to the Symfony HttpKernel.
- RoadRunner gRPC workers exposing `autoflow.core.v1.PlatformHealthService/Ping` as a transport smoke check.
- JSON and Protobuf serialization ports plus HTTP/gRPC status mapping.
- Messenger correlation stamp/middleware for services that configure Messenger.
- A transport-neutral integration-event envelope.
- A Problem Details response factory for explicit HTTP error mapping.

Existing `/health` routes remain unchanged for current Docker Compose health checks. See [CoreBundle documentation](packages/core-bundle/README.md) and [Wiki chapter 13](docs/wiki/13-shared-core-bundle.md). Readiness checks for real dependencies must be registered by each service. The first gRPC slice currently exposes a transport-level Ping method; service-owned business RPC contracts and REST-to-gRPC client calls are the next implementation step, not yet claimed as complete.

## Distributed tracing quick start

The Offer and Pricing services are Symfony 7.4 applications with independent Composer projects.
Pricing now exposes a first domain-backed quote calculation endpoint. The older Offer → Pricing
GET path remains available for tracing compatibility and still returns clearly labelled static demo data.

Trigger one request across both services:

~~~bash
curl http://localhost:8081/api/offers/demo-vehicle-001 \\
  -H "Authorization: Bearer $TOKEN"
~~~

Calculate an illustrative lease quote directly through Pricing:

~~~bash
curl -X POST http://localhost:8082/api/pricing/quotes \\
  -H "Authorization: Bearer $TOKEN" \\
  -H 'Content-Type: application/json' \\
  -d '{"vehicleId":"vehicle-001","vehiclePrice":"30000.00","downPayment":"3000.00","residualValue":"9000.00","currency":"EUR","durationMonths":36,"annualInterestRateBasisPoints":500}'
~~~

Check both health endpoints:

~~~bash
curl http://localhost:8081/health
curl http://localhost:8082/health
~~~

Both services export traces to Tempo over OTLP HTTP/protobuf. The Symfony auto-instrumentation
creates framework and HTTP client spans, propagates W3C Trace Context between services, and
each controller adds a business-operation span (offer.compose or pricing.calculate).

Open Grafana, choose Explore, select Tempo, and search for
autoflow-offer-service or autoflow-pricing-service. Open a trace to inspect the complete
request path across both Symfony applications.

See docs/observability/opentelemetry.md for the configuration, trace propagation details and
production considerations.

## RabbitMQ and Messenger

RabbitMQ Management is the operational UI for the RabbitMQ transport used by Symfony Messenger:
it lets you inspect queues, ready/unacknowledged messages, consumers, exchanges and rates.
It is not a Symfony Messenger application dashboard. A Symfony-level failed-message/retry
screen can be added later in an application admin/API, while failed transports can be managed
with Messenger commands such as messenger:failed:show and messenger:failed:retry.

## Observability architecture

- Grafana is provisioned with Prometheus, Loki and Tempo data sources.
- Prometheus scrapes RabbitMQ, PostgreSQL, Redis, Elasticsearch, cAdvisor and Node Exporter.
- Grafana Alloy discovers Docker containers and ships their stdout/stderr logs to Loki.
- Symfony applications export OpenTelemetry traces to Tempo using OTLP HTTP/protobuf (4318).
- Kibana is version-pinned to the same 8.17.5 release as Elasticsearch.

The Docker socket is mounted read-only into Alloy so it can discover container logs. Access to
the Docker socket is highly privileged; this setup is intended for a trusted local development
machine only. The exporters and monitoring endpoints are also intended for local development.

## Mercure and real-time notifications

Symfony applications publish updates to the internal URL
http://mercure/.well-known/mercure. Browser clients subscribe through
http://localhost:3001/.well-known/mercure. Publisher and subscriber JWT keys are
configured separately in .env. Generate strong random values before sharing the setup.
Do not expose the local HTTP hub or development secrets to the public internet.

## Quality commands

Run these from the repository root:

| Command | Purpose |
| --- | --- |
| make quality | Run all quality checks and Composer audit |
| make cs / make cs-fix | Check / automatically fix formatting |
| make phpcs | Check PSR-12 coding standard |
| make phpstan | Static analysis, level 8 |
| make phpmd | Complexity, design and code-smell checks |
| make rector / make rector-fix | Preview / apply automated refactoring |
| make test | Run PHPUnit |
| make coverage | Run tests with Xdebug coverage |
| make audit | Audit Composer dependencies |
| make shell | Open a shell in the PHP container |
| make down | Stop the stack |

## Xdebug and PhpStorm

1. Configure the PHP CLI interpreter in PhpStorm to use the Docker Compose service php.
2. Enable listening for PHP debug connections and use port 9003.
3. Add a PhpStorm server named AutoFlow with path mapping from the project root to
   /var/www/auto-flow.
4. Xdebug is configured with trigger mode. For a CLI debugging session, run:

~~~bash
docker compose exec -e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=1 php php -v
~~~

For coverage, the Composer coverage script activates XDEBUG_MODE=coverage.

## Background workers, scheduling and Temporal

Docker Compose runs the two long-lived Offer processes under Supervisor:
- `offer-expiration` polls for due offers every 30 seconds.
- `offer-outbox-publisher` publishes committed Outbox events to RabbitMQ.

Kubernetes uses native controllers instead of nesting Supervisor inside Pods:
- Offer API and Offer Outbox publisher are Deployments.
- Offer expiration is a one-shot CronJob (`*/1 * * * *`) using `php bin/expire-offers.php --once`, with `concurrencyPolicy: Forbid`.
- Vehicle Catalog API and its Outbox publisher are separate Deployments.

Temporal is added as a local development server using the official Temporal CLI container image. State is persisted in the `temporal-data` Docker volume. The gRPC endpoint is `localhost:7233`; the UI is `http://localhost:8233`. This is a development server, not a production Temporal cluster. The PHP SDK Workflow/Worker implementation is a separate step; adding the server does not mean a Workflow is already running.

Start the relevant services:

~~~bash
docker compose up -d --build offer-service vehicle-catalog-service offer-workers vehicle-catalog-outbox-publisher temporal
docker compose ps offer-workers temporal
docker compose logs -f offer-workers temporal
~~~

Apply Kubernetes workloads after building and tagging local images:

~~~bash
kubectl apply -f k8s/pricing/namespace.yaml

# Create the local Secrets first (copy/edit each secret.example.yaml).
kubectl apply -f k8s/offer/configmap.yaml
kubectl apply -f k8s/offer/secret.yaml
kubectl apply -f k8s/offer/deployment.yaml
kubectl apply -f k8s/offer/service.yaml
kubectl apply -f k8s/offer/outbox-publisher-deployment.yaml
kubectl apply -f k8s/offer/expire-offers-cronjob.yaml

kubectl apply -f k8s/vehicle-catalog/configmap.yaml
kubectl apply -f k8s/vehicle-catalog/secret.yaml
kubectl apply -f k8s/vehicle-catalog/deployment.yaml
kubectl apply -f k8s/vehicle-catalog/service.yaml
kubectl apply -f k8s/vehicle-catalog/outbox-publisher-deployment.yaml

kubectl -n autoflow get deployments,pods,services,cronjobs
kubectl -n autoflow get jobs
~~~

Kubernetes Secret manifests are templates only. Copy each `secret.example.yaml` to `secret.yaml`, replace local credentials, and apply the generated Secret. Never commit real credentials. These local Kubernetes examples reach Compose-hosted PostgreSQL, RabbitMQ and Keycloak through `host.docker.internal`; replace those addresses with in-cluster Services or managed endpoints in a real cluster.

## Architecture direction

This repository contains independently deployable Symfony services in a monorepo.
Each service owns its database and domain model. HTTP REST remains the public synchronous interface; gRPC is being introduced for internal synchronous service calls. RabbitMQ/Symfony Messenger handles asynchronous messages; Temporal handles durable long-running workflows. Transactional Outbox and idempotent consumers will protect event delivery.

Elasticsearch will support vehicle search, filtering and indexing; PostgreSQL remains the
source of truth for transactional data. Mercure is for pushing live updates to clients, not
for replacing RabbitMQ or Temporal in backend workflows.


## Engineering and architecture wiki

The architecture documentation explains the product domain, service boundaries, DDD, Clean / Hexagonal Architecture, communication patterns, data consistency, quality practices and the reasoning behind major technology choices.

Start with the [AutoFlow Engineering & Architecture Wiki](docs/wiki/README.md). The chapters distinguish implemented capabilities, technical demonstrations and target architecture so that planned work is not confused with delivered functionality.
