# 16 — Vehicle Catalog bounded context and context map

## Purpose

The Vehicle Catalog is the authoritative source for the identity and technical description of vehicles that AutoFlow makes discoverable. It answers: **what vehicle is this?** It does not answer: **what does this customer pay, can this customer order it, or when can it be delivered?**

This is the approved domain boundary for the first increment and is implemented by the Vehicle Catalog microservice.

## Bounded context

**Name:** Vehicle Catalog  
**Responsibility:** maintain canonical vehicle identity, taxonomy, generations, variants and technical specifications.

| Concept | Meaning | Owns |
| --- | --- | --- |
| Manufacturer | A vehicle-producing brand/company in the catalogue | Stable identity, display name, normalized code |
| Model | A named model line belonging to a manufacturer | Stable identity, manufacturer reference, model name |
| Generation | A generation/version of a model | Stable identity, model reference, generation label and known production period |
| VehicleVariant | A concrete technical configuration within a generation | Variant identity, engine, transmission, body style and measured specifications |
| VehicleMediaReference | A reference to an image or other media asset | Media identifier and usage metadata; binary storage belongs to Media |

## Sprint 1 domain decisions (issue #21)

The initial implementation uses the following deliberately conservative decisions:

- A variant is identified by a generated opaque 128-bit catalogue ID. A manufacturer variant code is optional metadata with a uniqueness constraint when present; it is not the primary identity.
- Model identity is unique within a manufacturer by normalized model name. Generation identity is unique within a model by generation label.
- Production start/end years are optional because historical catalogue sources may be incomplete. If both are known, end year cannot precede start year.
- Fuel type, powertrain, body style and transmission use explicit controlled vocabularies. The first vocabulary set is intentionally small and can evolve through an API/schema versioning decision.
- Technical values use explicit units in field names: power kW, displacement cm³, dimensions mm, curb weight kg and CO₂ g/km. Optional values remain nullable when the source is unknown.
- Lifecycle states are draft, published, discontinued and archived. New records are draft. A catalogue manager explicitly publishes a variant; publication also publishes non-archived ancestors. Archived variants cannot be republished.
- Media is stored as references with usage metadata; binary content is owned by Media.
- Pricing, discounts, stock/inventory, customer eligibility, commercial availability and delivery promises are not catalogue fields.

These are AutoFlow project decisions for the first increment, not claims about another company's catalogue. They are recorded in ADR-017. Unknown future requirements (localized labels, model-year semantics, source provenance and equipment packages) remain open rather than being guessed.

## Context map

- **Vehicle Catalog → Vehicle Search:** publish vehicle-created/updated events. Search owns its index and read projection.
- **Vehicle Catalog → Offer:** expose vehicle identity and technical details; Offer owns commercial configuration and offer lifecycle.
- **Offer → Pricing:** send selected configuration and pricing inputs; Pricing owns pricing policies and quote calculations.
- **Vehicle Catalog → Order Management:** provide identity references. Order owns its lifecycle and submitted commercial snapshot.
- **Vehicle Catalog → Media:** store media references; Media owns files, processing and delivery.

## Ownership rules

- Vehicle Catalog owns manufacturer, model, generation, variant and technical-specification data.
- Vehicle Search owns its search index. It consumes catalogue events and never writes to the catalogue database.
- Offer owns the commercial offer/configuration lifecycle and any historical snapshot required to preserve an offer.
- Pricing owns pricing policies, calculation inputs, quote results and quote validity. It does not own vehicle taxonomy.
- Order Management owns the order lifecycle and submitted commercial snapshot.
- Media owns media files. Catalogue records store references, not file bytes.

## Identity and consistency

- Use stable opaque identifiers for entities exposed across service boundaries.
- A foreign service stores an identifier, not a Doctrine relationship or cross-database foreign key.
- A catalogue update must not silently rewrite an accepted quote or submitted order. Historical commercial snapshots belong to Offer / Order.
- Search indexing is eventually consistent. The catalogue database remains the source of truth.
- Publish integration events only after the catalogue transaction commits; use an Outbox when reliable publication is required.

## Implemented API contract

All endpoints are versioned under `/api/v1` and require a verified bearer JWT from the shared CoreBundle.

| Method | Path | Scope | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/manufacturers` | `catalog:read` | List published manufacturers |
| GET | `/api/v1/vehicles` | `catalog:read` | Search and paginate published variants |
| GET | `/api/v1/vehicles/{variantId}` | `catalog:read` | Retrieve a published variant |
| POST | `/api/v1/manufacturers` | `catalog:manage` | Register a draft manufacturer |
| POST | `/api/v1/models` | `catalog:manage` | Register a draft model |
| POST | `/api/v1/generations` | `catalog:manage` | Register a draft generation |
| POST | `/api/v1/vehicles` | `catalog:manage` | Register a draft technical variant |
| POST | `/api/v1/vehicles/{variantId}/publish` | `catalog:manage` | Publish the variant and its non-archived ancestors |

Request and response DTOs form the HTTP boundary. OpenAPI documents the operations and response schemas. Errors use RFC 9457-style Problem Details with stable catalogue error codes. The catalogue is shared reference data, so it deliberately has no tenant-owned foreign keys or tenant-specific pricing/availability fields.

## Deferred domain questions

These are follow-up product decisions, not blockers for the implemented first increment:

1. Which specification fields become mandatory for each vehicle category?
2. How should model years and overlapping generation production periods be represented?
3. Which locale-specific names and unit conversions are required?
4. What source provenance, review and bulk-import workflow should be supported?
5. Should equipment packages and trim-level hierarchies become first-class concepts?

The first increment deliberately keeps optional technical values nullable and uses the controlled vocabularies and explicit units listed above.

## Definition of a good boundary

The boundary is healthy when Pricing can calculate a quote without importing catalogue entities, Search can rebuild its index from catalogue events, and Order can preserve a historical vehicle snapshot without querying the catalogue database during every state transition.

## Implementation status

**Status:** merged into `master` through PR #46. The merge is complete; CI quality and operational hardening are tracked independently.

The implementation includes:

- Pure Domain aggregates/value objects and lifecycle rules, with no Doctrine or Symfony dependency.
- Application command/query handlers, repository port and explicit `catalog:read` / `catalog:manage` scope enforcement.
- Doctrine ORM 3.5 entities and repository adapter in Infrastructure, PostgreSQL migration, dedicated runtime role and deterministic SQL demo fixtures.
- Transactional catalogue writes with an Outbox record; a standalone PDO/RabbitMQ publisher claims pending events with `FOR UPDATE SKIP LOCKED` and publisher confirms.
- Symfony REST controllers, request/response DTOs, OpenAPI schemas, Problem Details and the shared CoreBundle JWT authentication.
- Unit tests, API/controller tests, PostgreSQL repository tests and a create → publish → retrieve integration journey.

The catalogue is the global source of truth for vehicle identity and technical data. Offer, Pricing, Search, Order and Media retain ownership of their respective commercial, search, order and binary-media concerns.
