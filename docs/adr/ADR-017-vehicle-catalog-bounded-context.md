# ADR-017 — Define the Vehicle Catalog bounded context and initial identity model

**Status:** Accepted for Sprint 1 implementation  
**Date:** 2026-11-05  
**Related:** Epic #20; issues #21–#28; Wiki chapter 16

## Context

AutoFlow needs an authoritative source for manufacturer, model, generation, vehicle-variant identity and technical specifications. The service must support discovery and downstream offer configuration without taking ownership of commercial terms, stock promises or customer/order state.

The first increment must be deterministic and useful without pretending that unresolved real-world catalogue policies are known.

## Decision

1. Deploy Vehicle Catalog as an independent Symfony 7.4 / PHP 8.4 application with a service-owned PostgreSQL schema/tables and repository adapter.
2. Keep Domain and Application independent of Symfony, Doctrine, HTTP, PDO and RabbitMQ. Infrastructure owns Doctrine ORM/DBAL mappings and repository adapters; the standalone Outbox relay uses a narrow PDO adapter for atomic row claiming and RabbitMQ publication. Presentation maps HTTP request/response DTOs.
3. Use generated opaque 128-bit hexadecimal IDs for Manufacturer, Model, Generation and VehicleVariant. Manufacturer-provided variant codes are optional unique metadata, not canonical identity.
4. Enforce model-name uniqueness within a manufacturer using a normalized name. Enforce generation-label uniqueness within a model.
5. Treat production year bounds as optional. When both exist, end year must not precede start year.
6. Use controlled vocabularies for fuel type, powertrain, body style and transmission. Keep measurements nullable where source data is unknown and encode units in field names.
7. Use draft, published, discontinued and archived lifecycle states. Creation starts in draft. A scoped catalogue manager publishes a variant and its non-archived ancestors. Archived variants cannot be republished.
8. Store media identifiers and usage metadata only; Media owns binary storage, processing and delivery.
9. Exclude lease prices, discounts, quote validity, customer eligibility, stock/inventory, commercial availability, delivery dates and order lifecycle.
10. PostgreSQL is authoritative. Search is a derived, eventually consistent projection owned by Vehicle Search. Publish versioned integration events through a transactional Outbox and RabbitMQ publisher confirms.
11. The catalogue is a global reference dataset in this increment, not tenant-owned commercial data. All API routes require a verified JWT and explicit scope. Reads require `catalog:read`; management writes require `catalog:manage`. The local demo user receives read scope only.
12. Expose versioned REST endpoints under `/api/v1`; never serialize persistence records or expose SQL/internal exception details.

## Alternatives considered

- **Manufacturer code as variant primary key:** rejected because external codes can be missing, reused, reformatted or changed. Keep as optional unique metadata.
- **Tenant-owned catalogue records:** rejected for the first increment because the Wiki defines one authoritative discoverable catalogue; tenant-specific commercial availability belongs to Offer/Inventory contexts.
- **Elasticsearch as write store:** rejected because the catalogue requires relational constraints and transactional aggregate changes. Elasticsearch remains a derived Search-owned index.
- **Direct RabbitMQ publish in the request transaction:** rejected because database commit and broker publication cannot be made atomic with a simple dual write. Use a transactional Outbox.
- **Doctrine entities in Domain:** rejected to preserve framework-independent business policy. Use Doctrine ORM 3.5 attribute mappings and repository adapters as required by issue #26. Keep the Domain/Application layers independent. The Outbox relay may use PDO for its PostgreSQL-specific SKIP LOCKED claim loop.
- **Automatic publication on creation:** rejected because draft/review lifecycle must be explicit.

## Consequences

- Foreign services store opaque IDs and their own immutable snapshots; no cross-service database foreign keys or shared domain classes.
- Search consumers must be idempotent and tolerate duplicate delivery. The Outbox publisher uses persistent messages, confirms, retry backoff and a durable topic exchange.
- Vocabulary changes and event payload changes require explicit compatibility/versioning review.
- Catalogue management requires a trusted role assignment. The demo user cannot publish records.
- Initial technical vocabularies, media reference metadata and production-year semantics may evolve when concrete requirements are established.

## Open questions

- Locale-specific labels and translations.
- Source provenance, import review, and catalogue correction workflows.
- Optional equipment/package modelling.
- Model-year semantics versus calendar production years.
- Media usage vocabulary and localization.
- Whether separate tenant-specific inventory/availability contexts will be required.

These questions are intentionally not guessed in Sprint 1.
