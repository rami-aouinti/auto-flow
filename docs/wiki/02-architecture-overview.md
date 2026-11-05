# 02 — System and architecture overview

## 1. Architectural style

AutoFlow uses a **monorepo with independently deployable Symfony applications**.

A monorepo is a source-code organisation choice: multiple applications and infrastructure definitions live in one Git repository. It does not mean that applications run in one process or must be deployed together.

The target is a service-oriented / microservice architecture with clear business capability boundaries, independent application lifecycles and explicit data ownership. The current repository is an early implementation of that direction. Offer and Pricing are small technical demonstrations, not a complete leasing platform.

## 2. Logical architecture

The platform can be understood in four areas:

- **Experience and entry points:** web/mobile clients and public APIs.
- **Business capabilities:** Vehicle Catalog, Offer, Pricing and future domain services.
- **Integration and workflow:** synchronous HTTP, asynchronous messaging and durable orchestration.
- **Platform capabilities:** persistence, cache, search, observability and runtime.

These are logical responsibility groups. They do not require every group to become a separate deployable service.

## 3. Current runtime demonstration

The initial implemented HTTP path is:

~~~text
Client
  |
  | GET /api/offers/{vehicleId}
  v
Offer Service (Symfony, host port 8081)
  |
  | Symfony HttpClient + W3C trace context
  v
Pricing Service (Symfony, host port 8082)
  |
  | response
  v
Offer Service
  |
  | composed JSON response
  v
Client
~~~

Inside Docker Compose, Offer calls Pricing using the Docker DNS name pricing-service and container port 8080. Host ports 8081 and 8082 are for local access.

Each application has its own Symfony application and Composer project. They do not call one another's PHP classes directly; the integration boundary is HTTP.

The pricing response is static demo data. It validates service communication and tracing, not a production pricing engine. The Vehicle Catalog is a separate authoritative service on host port 8084; it owns manufacturer/model/generation/variant records and publishes versioned catalogue events through its Outbox. It does not participate in the Offer → Pricing request path.

## 4. Local platform components

Docker Compose currently provisions:

- PostgreSQL for relational persistence.
- RabbitMQ for message transport.
- Redis for cache and ephemeral data use cases.
- Elasticsearch and Kibana for search/index inspection.
- Mercure for real-time Server-Sent Events.
- Mailpit for local email testing.
- Prometheus and infrastructure exporters for metrics.
- Grafana for exploration and dashboards.
- Loki and Grafana Alloy for centralized container logs.
- Tempo for distributed traces.

A dependency in Compose is not proof that a business workflow already uses it. Verify application configuration and code before describing an integration as implemented.

## 5. Why a monorepo?

Benefits:
- Coordinated cross-service contract changes.
- Shared CI conventions and quality tooling.
- Reproducible local orchestration.
- Atomic documentation and infrastructure changes.
- Reusable build definitions.

Costs and controls:
- CI should avoid rebuilding unrelated services where practical.
- Service ownership and dependency boundaries must remain explicit.
- Shared libraries must not become a channel for sharing business internals.
- Independent deployments still require versioned artefacts and compatible contracts.

A monorepo is not permission for services to import one another's domain classes.

## 6. Why independent Symfony applications?

They provide separate dependency graphs, configuration, runtime and deployment lifecycles. They clarify ownership and force integrations to use explicit contracts.

The trade-off is distributed-systems complexity: networking, deployment, contract compatibility, observability, retries and distributed consistency become engineering responsibilities.

For a small team or early product, a modular monolith may be better. Microservices are justified when the business and organisational benefits outweigh operational cost.

## 7. Service boundary rules

A service owns its business rules, state transitions, domain model, persistence mapping, API/event contracts, validation and operational telemetry.

Other services should not read or write its tables directly. They should use a documented API or consume published integration events.

Shared packages should contain stable technical capabilities only where extraction is justified. Do not put Offer, Pricing, Order or Customer business entities into a universal shared bundle.

## 8. Deployment evolution

**Current:** Docker Compose for local development and integration.

**Target:** CI-built immutable images, environment-specific configuration, secrets management, deployment automation and Kubernetes only when operational needs justify it.

Kubernetes is not an architecture quality badge. Before adopting it, services need reliable health checks, graceful shutdown, externalized state, telemetry, resource limits and repeatable deployment artefacts.

## 9. Quality attributes

Evaluate the architecture against availability, latency, throughput, data correctness, auditability, security, privacy, maintainability, independent deployability, observability, cost and operational simplicity.

Every major choice should state which qualities it improves and which trade-offs it introduces.