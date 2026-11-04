# 07 — Architecture Decision Records

## 1. Why ADRs exist

An Architecture Decision Record captures a significant decision and its reasoning. It explains the context, alternatives, rationale, trade-offs and conditions under which the decision should be revisited.

Status values:
- **Accepted** — guides implementation.
- **Proposed** — under discussion, not binding.
- **Superseded** — replaced by a later decision.
- **Deprecated** — no longer recommended.

These records describe current direction and known implementation status. Revise them when code or requirements change.

---

## ADR-001 — Use a monorepo

**Status:** Accepted

**Context:** Multiple Symfony applications and shared infrastructure need coordinated review and reproducible local development.

**Decision:** Keep applications, Docker definitions, CI conventions and architecture documentation in one repository while preserving independent application/deployment boundaries.

**Alternatives:** One repository per service; one Symfony application for every capability.

**Rationale:** A monorepo simplifies local orchestration and coordinated changes without forcing one runtime or shared domain model.

**Consequences:** CI should become path-aware; shared code must be controlled; independent deployment still needs versioned images and compatible contracts.

---

## ADR-002 — Use independent Symfony applications

**Status:** Accepted as target direction; Offer and Pricing demonstrate the approach.

**Context:** Business capabilities may need independent ownership, dependencies and release lifecycles.

**Decision:** Package each service as an independent Symfony application with its own Composer project and runtime container.

**Alternatives:** One large application; modular monolith; services sharing domain packages.

**Rationale:** Independent applications make runtime and deployment boundaries explicit.

**Consequences:** Explicit contracts, distributed tracing and failure handling are required. Services must not import one another's domain classes. A modular monolith remains valid when operational cost outweighs the benefit.

---

## ADR-003 — Apply DDD and inward dependency direction

**Status:** Accepted as design principle

**Context:** Business rules should remain clear and independent from framework details.

**Decision:** Use DDD to discover bounded contexts and Clean / Hexagonal Architecture to protect domain and application policies from infrastructure dependencies.

**Alternatives:** Framework-first CRUD structure; generic shared domain model; business logic in controllers.

**Rationale:** Explicit boundaries and dependency direction improve testability and independent evolution.

**Consequences:** Abstractions have a cost and must be justified. Domain models should not become Doctrine-specific containers. Context boundaries need validation against business language.

---

## ADR-004 — Use synchronous HTTP for immediate Offer-to-Pricing collaboration

**Status:** Accepted for current demonstration

**Context:** Offer needs a pricing result before it can return a composed response.

**Decision:** Offer calls Pricing through HTTP using Symfony HttpClient.

**Alternatives:** Asynchronous request/result messaging; shared database reads; shared PHP classes.

**Rationale:** The caller needs an immediate answer, and HTTP keeps the service boundary explicit.

**Consequences:** Offer latency and availability depend on Pricing. Timeouts, safe retries and contract compatibility are required. Current Pricing data is static demo data, not a production calculation.

---

## ADR-005 — Use RabbitMQ with Symfony Messenger for asynchronous jobs

**Status:** Accepted as integration direction; verify each business consumer before claiming implementation.

**Context:** Some tasks should not hold an HTTP request open and need independent retries.

**Decision:** RabbitMQ is the broker transport; Symfony Messenger handles application dispatch, handlers, retry and failure transports.

**Alternatives:** Synchronous HTTP for everything; database polling; a workflow engine for every background job.

**Rationale:** A broker decouples producer and consumer availability and smooths bursts.

**Consequences:** Consumers must be idempotent; retries and failure transport need policies; queue depth and age need monitoring; message schemas need compatibility management.

---

## ADR-006 — Reserve Temporal for durable long-running orchestration

**Status:** Proposed target direction; not currently deployed in Compose

**Context:** A leasing process may wait for partner responses, timers, manual decisions or retries over an extended period.

**Decision:** Use Temporal where durable workflow state and orchestration are required. Do not replace every Messenger message with a workflow.

**Alternatives:** Chain of Messenger handlers; custom database state machine; synchronous orchestration in one HTTP request.

**Rationale:** Temporal supports durable workflow progression across worker restarts and time.

**Consequences:** Workflow code must be deterministic; external I/O belongs in Activities; workflow/activity versioning and operations are required; infrastructure and learning cost increase. Short independent jobs can remain on Messenger.

---

## ADR-007 — Use Mercure for client-facing live updates

**Status:** Accepted as delivery direction; complete business flows require verification

**Context:** A browser may need live status changes without repeated polling.

**Decision:** Use Mercure / Server-Sent Events for authorized client subscriptions.

**Alternatives:** Polling; WebSockets; sending all backend messages directly to browsers.

**Rationale:** SSE suits server-to-client updates and separates browser delivery from backend message processing.

**Consequences:** Publisher/subscriber authorization and topic design are critical. Mercure does not replace RabbitMQ or workflow orchestration.

---

## ADR-008 — Keep PostgreSQL authoritative and Elasticsearch derived

**Status:** Accepted as data strategy

**Context:** Transactional business state and search workloads have different access patterns.

**Decision:** PostgreSQL stores authoritative relational state; Elasticsearch serves search-oriented projections.

**Alternatives:** Elasticsearch as transactional source of truth; PostgreSQL for all search; independent dual writes.

**Rationale:** PostgreSQL provides relational transactions and constraints; Elasticsearch provides search and relevance capabilities.

**Consequences:** Search is eventually consistent. Reliable event publication, idempotent indexing, rebuild and reconciliation procedures are required.

---

## ADR-009 — Use OpenTelemetry and Tempo for distributed traces

**Status:** Accepted and demonstrated by Offer → Pricing

**Context:** A request crosses service boundaries, so isolated logs are insufficient to explain end-to-end latency and failures.

**Decision:** Instrument with OpenTelemetry, propagate W3C Trace Context and export OTLP traces to Tempo. Explore traces in Grafana.

**Alternatives:** Vendor-specific tracing only; correlation IDs without spans; logs alone.

**Rationale:** OpenTelemetry provides vendor-neutral instrumentation and context propagation.

**Consequences:** Sampling and instrumentation need operational configuration. Sensitive data must not enter spans. Metrics and profiling need their own collection paths.

---

## ADR-010 — Use Compose locally; defer Kubernetes until justified

**Status:** Accepted

**Context:** Developers need a repeatable local environment.

**Decision:** Use Docker Compose locally. Consider Kubernetes when deployment, scaling, isolation or operational needs justify the added complexity.

**Alternatives:** Install dependencies directly; introduce Kubernetes immediately; shared remote development environment.

**Rationale:** Compose is simpler for local integration. Kubernetes should solve a demonstrated problem, not be a portfolio checkbox.

**Consequences:** Compose is not a production deployment architecture. Production secrets, network policies, resource limits, resilience and deployment processes require separate design.

---

## ADR-011 — Share technical cross-cutting capabilities through CoreBundle

**Status:** Accepted

**Context:** Offer and Pricing are independent Symfony applications. Reimplementing request correlation, HTTP propagation and common operational helpers in each service would create inconsistent behavior. Sharing business models, however, would couple bounded contexts and undermine independent evolution.

**Decision:** Maintain a small Symfony bundle at `packages/core-bundle` for technical cross-cutting concerns only. Distribute it through Composer path repositories in the monorepo and copy it into each service image during the Docker build.

**Alternatives:** Duplicate utilities in every service; merge all services into one application; create a shared domain package; publish a remote package before the API is stable.

**Rationale:** A local Composer package gives reuse while preserving independent service containers and Composer projects. The Docker build copies the package into the build image, so runtime does not rely on host-mounted symlinks.

**Consequences:** The package API needs semantic versioning and compatibility discipline. Services opt into capabilities such as Messenger middleware and readiness checks. Domain models, repositories, event semantics, Outbox persistence, Temporal workflows, authentication and business policies remain owned by their bounded contexts. A shared bundle must stay smaller than the sum of the service responsibilities it supports.

**Revisit when:** A service needs a different runtime/framework, the bundle becomes a dependency bottleneck, or shared code starts expressing business concepts.

---

## 2. ADR template

~~~markdown
# ADR-NNN — Short decision title

**Status:** Proposed | Accepted | Superseded | Deprecated
**Date:** YYYY-MM-DD

## Context
What problem or constraint requires a decision?

## Requirements and constraints
What must the solution achieve? Which quality attributes matter?

## Options considered
Which realistic alternatives were evaluated?

## Decision
What was selected?

## Rationale
Why is this option preferable under current constraints?

## Consequences
What becomes easier or harder? Which risks appear?

## Validation
Which tests, measurements or operational evidence will validate it?

## Revisit when
Which changed conditions should trigger a review?
~~~

## 3. Review principle

An ADR is not permanent truth. Revisit it when scale, team structure, business requirements, operational evidence or technology constraints change. A good architecture is intentionally evolvable.