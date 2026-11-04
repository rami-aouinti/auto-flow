# AutoFlow Mobility Platform — Product Roadmap

AutoFlow is an independent vehicle-leasing and mobility platform learning project inspired by publicly observable customer journeys. It does not reproduce MeinAuto.de's private architecture or proprietary data.

## Delivery principles

- Two-week iterations; exact calendar dates and capacity are agreed before each sprint.
- Every sprint delivers a demonstrable customer or operational capability.
- Each issue must include a user story, scope, acceptance criteria, dependencies and test expectations.
- Epics describe business outcomes; issues describe reviewable engineering work.
- Do not mark a capability implemented until code, tests and documentation verify it.
- Start with a modular, well-bounded design; extract independently deployable services when justified.

## Product journey

1. Discover and compare vehicles.
2. Configure leasing or financing terms.
3. Request an offer.
4. Submit an application and complete eligibility checks.
5. Work with a sales advisor.
6. Place and manage an order.
7. Prepare and sign the contract.
8. Coordinate dealer and vehicle delivery.
9. Track the journey in a customer portal.

## Sprint roadmap

| Sprint | Epic / capability | Demonstrable outcome |
| --- | --- | --- |
| S0 | Platform foundation and security | Verified service identity, tenant context, authorization, edge routing, API lifecycle and contract checks. Build on ADR-016 incrementally; do not claim target capabilities as implemented. |
| S1 | Vehicle Catalog Foundation | Canonical manufacturer, model, generation and variant data; service-owned PostgreSQL persistence; versioned REST API and tests. [Epic #20](../issues/20) |
| S2 | Vehicle Search | Search projection, Elasticsearch indexing, filters, sorting, pagination and relevance tests. |
| S3 | Offer & Pricing | Configurator inputs, pricing policies, immutable quote snapshots, validity and pricing contract tests. |
| S4 | Customer & Identity | Customer profile boundary, identity-provider integration, consent/contact preferences and tenant-aware access. |
| S5 | Application & CRM | Application submission, eligibility workflow, sales assignment, advisor tasks and interaction history. |
| S6 | Order Management | Order aggregate, explicit state machine, idempotent submission, audit history and transactional Outbox. |
| S7 | Financing & Contract | Financing options, contract preparation/versioning, document references and signature-provider boundary. |
| S8 | Dealer & Delivery | Dealer/partner capability, allocation, delivery milestones and external partner integration. |
| S9 | Customer Portal | Customer-facing application/order/contract status and authorized live updates. |
| S10 | Production Readiness | Security review, observability, resilience, load tests, backup/restore, deployment and runbooks. |

## Sprint 1 — Vehicle Catalog Foundation

**Epic:** [#20 — Vehicle Catalog](../issues/20)

- [#21 — Define the bounded context and domain language](../issues/21)
- [#22 — Implement Manufacturer aggregate and domain tests](../issues/22)
- [#23 — Implement Vehicle Model and Generation domain](../issues/23)
- [#24 — Implement VehicleVariant and technical specifications](../issues/24)
- [#25 — Create application use cases and ports](../issues/25)
- [#26 — Add PostgreSQL persistence, repositories and migrations](../issues/26)
- [#27 — Expose versioned REST API and OpenAPI contract](../issues/27)
- [#28 — Add integration tests, fixtures and quality gates](../issues/28)

## Sprint planning rules

Before starting a sprint:
- Confirm the sprint goal and available capacity.
- Review dependencies and acceptance criteria.
- Estimate issues as a team; estimates are not time promises.
- Keep work small enough to review and test.
- Identify security, privacy, data migration and operational concerns.

At sprint end:
- Demonstrate the working journey.
- Verify acceptance criteria and automated checks.
- Review unfinished work without silently carrying it as completed.
- Update this roadmap, the relevant wiki chapters and ADRs to reflect reality.

## GitHub Projects board

Use a GitHub Projects board with these suggested fields:
- **Status:** Backlog, Ready, In Progress, In Review, Done, Blocked.
- **Sprint:** S0 through S10.
- **Priority:** P0, P1, P2, P3.
- **Estimate:** story points.
- **Epic:** parent business capability.

Suggested views:
- Product Roadmap (group by Sprint).
- Sprint Board (group by Status, filter by current Sprint).
- Backlog (group by Epic).
- Blocked / Dependencies.

The issue tracker contains the detailed work items. The Project board should reference those issues rather than duplicate their descriptions.
