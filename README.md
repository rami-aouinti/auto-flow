# AutoFlow Mobility Platform

Enterprise mobility and vehicle-leasing portfolio project built with Symfony microservices.

> Independent learning project inspired by a digital vehicle-leasing journey. It does not
> describe MeinAuto.de's internal architecture.

## Stack

- PHP 8.4 CLI and Xdebug 3
- RoadRunner 3.0.1 HTTP and gRPC workers for the Offer and Pricing services
- Symfony 7.4 microservices with independent Composer projects
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
docker compose up -d --build
docker compose ps
docker compose exec php composer install
docker compose exec php php -v
~~~

The first local build of the two Symfony services installs their independent Composer dependencies, including development-only Swagger UI tooling. Production builds keep Composer's default `--no-dev` behavior. Use `make api-docs` to export both generated OpenAPI specifications.
Subsequent starts reuse the built images unless their source or Dockerfile changes.

## Local interfaces and endpoints

| Service | URL | Purpose |
| --- | --- | --- |
| Offer Service | http://localhost:8081 | Demo offer API, calls Pricing Service |
| Offer Swagger UI | http://localhost:8081/api/doc | Interactive Offer REST API documentation (dev only) |
| Offer OpenAPI JSON | http://localhost:8081/api/doc.json | Machine-readable Offer API contract (dev only) |
| Pricing Service | http://localhost:8082 | Demo pricing API |
| Pricing Swagger UI | http://localhost:8082/api/doc | Interactive Pricing REST API documentation (dev only) |
| Pricing OpenAPI JSON | http://localhost:8082/api/doc.json | Machine-readable Pricing API contract (dev only) |
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
| Redis | localhost:6379 | Cache and ephemeral data |
| OTLP gRPC / HTTP | localhost:4317 / 4318 | OpenTelemetry trace ingestion |

## Authentication quick start

Offer and Pricing validate RS256 access tokens locally using Keycloak's JWKS endpoint. Their expected audiences are distinct (`autoflow-offer` and `autoflow-pricing`). The `/health` and development-only Swagger endpoints remain public; `/api/*` requires a bearer token.

The imported `demo` user and password `demo`, and the local password-grant client below, are for local testing only. Do not enable the password grant or reuse these credentials in a shared or production environment. Real browser clients should use Authorization Code + PKCE.

~~~bash
TOKEN=$(curl -fsS -X POST http://localhost:8080/realms/autoflow/protocol/openid-connect/token \\
  -H 'Content-Type: application/x-www-form-urlencoded' \\
  --data-urlencode 'grant_type=password' \\
  --data-urlencode 'client_id=autoflow-local-client' \\
  --data-urlencode 'username=demo' \\
  --data-urlencode 'password=demo' | jq -r .access_token)

curl -i http://localhost:8081/api/offers/demo-vehicle-001 \\
  -H "Authorization: Bearer $TOKEN"

curl -i http://localhost:8082/api/pricing/quotes \\
  -H "Authorization: Bearer $TOKEN" \\
  -H 'Content-Type: application/json' \\
  -d '{"vehicleId":"vehicle-001","vehiclePrice":"30000.00","downPayment":"3000.00","residualValue":"9000.00","currency":"EUR","durationMonths":36,"annualInterestRateBasisPoints":500}'
~~~

Offer obtains a separate client-credentials token for its downstream Pricing call; it does not blindly forward the end-user bearer token. The user-to-service token exchange / delegated user context is a later authorization design decision.

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
curl http://localhost:8081/api/offers/demo-vehicle-001
~~~

Calculate an illustrative lease quote directly through Pricing:

~~~bash
curl -X POST http://localhost:8082/api/pricing/quotes \\
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

## Architecture direction

This repository contains independently deployable Symfony services in a monorepo.
Each service owns its database and domain model. HTTP REST remains the public synchronous interface; gRPC is being introduced for internal synchronous service calls. RabbitMQ/Symfony Messenger handles asynchronous messages; Temporal handles durable long-running workflows. Transactional Outbox and idempotent consumers will protect event delivery.

Elasticsearch will support vehicle search, filtering and indexing; PostgreSQL remains the
source of truth for transactional data. Mercure is for pushing live updates to clients, not
for replacing RabbitMQ or Temporal in backend workflows.


## Engineering and architecture wiki

The architecture documentation explains the product domain, service boundaries, DDD, Clean / Hexagonal Architecture, communication patterns, data consistency, quality practices and the reasoning behind major technology choices.

Start with the [AutoFlow Engineering & Architecture Wiki](docs/wiki/README.md). The chapters distinguish implemented capabilities, technical demonstrations and target architecture so that planned work is not confused with delivered functionality.
