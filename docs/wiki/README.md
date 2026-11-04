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

## How to interpret this documentation

Every statement belongs to one of three categories:

- **Implemented** — visible in the current repository and configuration.
- **Demonstrated** — a deliberately small technical proof of concept, not a complete business capability.
- **Target architecture** — a documented direction that still requires implementation, validation or operational hardening.

A professional architecture document must describe reality accurately. A roadmap item must never be presented as an implemented feature.

## Current implementation snapshot

The repository currently contains:

- A monorepo with independent Symfony applications for Offer and Pricing.
- PHP 8.4 and Symfony 7.4 service containers.
- PostgreSQL, RabbitMQ, Redis and Elasticsearch infrastructure in Docker Compose.
- A synchronous Offer → Pricing HTTP call.
- OpenTelemetry distributed tracing exported to Grafana Tempo.
- Grafana, Prometheus, Loki, Grafana Alloy and infrastructure exporters.
- Mercure and Mailpit for local integration scenarios.
- PHP quality tooling including PHPUnit, PHPStan, PHP-CS-Fixer, PHP_CodeSniffer, PHPMD and Rector.

The Offer and Pricing applications are technical demonstrations. Pricing currently returns static demo data. Full production bounded contexts, isolated service-owned data stores, complete Messenger business workflows, Temporal orchestration, a Transactional Outbox implementation and Kubernetes deployment must not be assumed to exist simply because they are discussed here.

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