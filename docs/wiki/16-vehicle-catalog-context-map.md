# 16 — Vehicle Catalog bounded context and context map

## Purpose

The Vehicle Catalog is the authoritative source for the identity and technical description of vehicles that AutoFlow makes discoverable. It answers: **what vehicle is this?** It does not answer: **what does this customer pay, can this customer order it, or when can it be delivered?**

This is the proposed domain boundary for Sprint 1, not a claim that the complete context is already implemented.

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

## Initial API shape

The exact contract is decided in issue #27. Candidate read operations:

- GET /api/v1/manufacturers
- GET /api/v1/vehicles
- GET /api/v1/vehicles/{vehicleVariantId}

Administrative write operations must be authenticated and authorized. Do not expose production write endpoints without an explicit identity and authorization decision.

## Open domain questions

Track unresolved decisions in issue #21 rather than guessing:

1. Is a variant uniquely identified by an internal catalogue ID, a manufacturer code, or both?
2. Which specification fields are mandatory for each vehicle category?
3. How are model years and overlapping generation production periods represented?
4. Are fuel, powertrain, body style and transmission controlled vocabularies?
5. How are discontinued, temporarily unavailable and unpublished variants distinguished?
6. Which locale-specific names and units are required?
7. What is the source and review process for catalogue imports?

## Definition of a good boundary

The boundary is healthy when Pricing can calculate a quote without importing catalogue entities, Search can rebuild its index from catalogue events, and Order can preserve a historical vehicle snapshot without querying the catalogue database during every state transition.

## Implementation status

**Status:** implementation is on `feature/vehicle-catalog-service`; final acceptance remains pending CI, integration verification and merge. The Wiki describes the approved first-increment decisions, not a claim that the feature is already deployed.
