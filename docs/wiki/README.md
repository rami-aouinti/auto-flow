# AutoFlow — Engineering & Architecture Wiki

This wiki documents the product, business domain, system architecture, design principles, integrations, data strategy, engineering quality and architectural decisions of AutoFlow Mobility Platform.

AutoFlow is an independent portfolio and learning project inspired by publicly observable digital vehicle-leasing journeys. It does not describe the private architecture, systems, data or implementation of MeinAuto.de or any other company.

## Chapters

| Chapter | Subject |
| --- | --- |
| [01 — Product vision](01-product-vision.md) | Business problem, actors, capabilities and customer journey |
| [02 — Architecture overview](02-architecture-overview.md) | Monorepo, Symfony applications, service boundaries and runtime topology |
| [03 — Domain and application design](03-domain-and-application-design.md) | DDD, Bounded Contexts, Clean / Hexagonal Architecture, dependency direction and SOLID |
| [04 — Communication and workflows](04-communication-and-workflows.md) | REST, RabbitMQ, Messenger, Temporal, Mercure, Outbox, retries and idempotency |
| [05 — Data architecture](05-data-architecture.md) | PostgreSQL, Redis, Elasticsearch, ownership and consistency |
| [06 — Quality, security and operations](06-quality-security-operations.md) | Tests, static analysis, Docker, CI/CD, security and observability |
| [07 — Architecture decisions](07-architecture-decisions.md) | ADRs: context, alternatives, rationale and trade-offs |
| [08 — Architecture diagrams](08-architecture-diagrams.md) | C4 views, deployment topology and sequence diagrams |
| [09 — Offer and Pricing code walkthrough](09-code-walkthrough-offer-pricing.md) | Existing service boundaries, HTTP integration and tracing |
| [10 — Domain and persistence model](10-domain-data-model.md) | Conceptual leasing model, ownership, money and lifecycle |
| [11 — Events and workflows](11-event-catalogue-and-workflows.md) | Event catalogue, Outbox, Messenger, Temporal and idempotency |
| [12 — Pricing Service domain](12-pricing-service-domain.md) | Money, lease invariants, annuity calculation, API contract and next steps |
| [13 — Shared CoreBundle](13-shared-core-bundle.md) | Technical shared capabilities, correlation, health, messaging and boundaries |
| [14 — Local developer tooling](14-local-developer-tooling.md) | Adminer, per-service Swagger UI, OpenAPI contracts and dev/prod isolation |
| [15 — Security and API platform](15-security-and-api-platform.md) | JWT/OIDC, tenant isolation, permissions, gateway, rate limits, API versions and contract tests |
| [16 — Vehicle Catalog context map](16-vehicle-catalog-context-map.md) | Vehicle domain, ownership boundaries, context map and open questions |
| [17 — Engineering delivery and agents](17-engineering-delivery-and-agents.md) | Issue-to-PR workflow, quality gates and AI agent rules |

## How to interpret this documentation

Every statement belongs to one of three categories:

- **Implemented** — visible in the current repository and configuration.
- **Demonstrated** — a deliberately small technical proof of concept, not a complete business capability.
- **Target architecture** — a documented direction that still requires implementation, validation or operational hardening.

A professional architecture document must describe reality accurately. A roadmap item must never be presented as an implemented feature.

## Current implementation snapshot

The repository currently contains:

- A monorepo with independent Symfony applications for Offer, Pricing and Vehicle Catalog. The Vehicle Catalog bounded context is merged into master; its quality and operational hardening continue through the shared CI pipeline.
- PHP 8.4 and Symfony 7.4 service containers.
- PostgreSQL, RabbitMQ, Redis and Elasticsearch infrastructure in Docker Compose.
- A synchronous Offer → Pricing HTTP call using the legacy demonstration endpoint.
- A new Pricing quote-calculation API with a Domain and Application layer.
- Integer-minor-unit Money values, lease-term invariants and a versioned annuity calculation.
- Unit tests for calculation and invalid domain inputs.
- OpenTelemetry distributed tracing exported to Grafana Tempo.
- Grafana, Prometheus, Loki, Grafana Alloy and infrastructure exporters.
- Mercure and Mailpit for local integration scenarios.
- A shared Symfony CoreBundle for correlation IDs, outgoing HTTP propagation, health endpoints, Messenger correlation primitives, integration-event metadata and Problem Details responses.

The Pricing calculation is an illustrative fixed-rate annuity model, not a regulated financial offer. Pricing policy persistence, quote snapshots, taxes, fees, customer-specific rules, authentication and production validation are not implemented yet. The legacy GET endpoint still returns static demonstration data so the existing Offer tracing path remains compatible.

## Architecture principles

1. Business boundaries before technology boundaries.
2. High cohesion and low coupling.
3. Business policies depend on abstractions, not frameworks or vendors.
4. Data ownership and consistency must be explicit.
5. Timeouts, retries, duplicate delivery and partial failures are normal design cases.
6. Security, privacy and auditability are part of the architecture.
7. Logs, metrics and traces must help operators explain what happened.
8. Prefer the simplest design that satisfies requirements; evolve when evidence justifies complexity.

## Maintaining the wiki

When a significant decision changes, update the relevant chapter and add or revise an Architecture Decision Record. Keep examples aligned with the code. If documentation and implementation disagree, investigate and correct the discrepancy.
