# 10 — Domain and persistence model

## 1. Scope and status

This chapter proposes a conceptual model for the future leasing domain. It is **not** a description of existing Doctrine entities or a current database schema. The current Offer and Pricing demonstration returns static data and does not persist the model below.

The purpose is to show how to reason about business ownership, identity, invariants and data boundaries before choosing tables.

## 2. Candidate bounded contexts

| Context | Owns | Should not own |
| --- | --- | --- |
| Catalogue | Vehicle catalogue identity and specifications | Customer order lifecycle |
| Pricing | Pricing policies and quote calculation | Order fulfilment state |
| Offer | Offer composition and validity | Payment settlement |
| Customer | Customer profile and contact preferences | Pricing policy |
| Order | Order submission and lifecycle | Search index internals |
| Contract | Contract documents and versions | Vehicle catalogue editing |
| Notification | Templates, preferences and delivery attempts | Business decision to accept an order |

The boundaries need validation with business requirements. A capability can remain a module inside a modular monolith until independent deployment is justified.

## 3. Conceptual relationship diagram

~~~mermaid
erDiagram
    VEHICLE ||--o{ QUOTE : priced_for
    QUOTE ||--o| OFFER : may_compose
    OFFER ||--o| ORDER : accepted_as
    CUSTOMER ||--o{ ORDER : submits
    ORDER ||--o{ CONTRACT : generates_versions
    ORDER ||--o{ ORDER_STATUS_HISTORY : records
    ORDER ||--o{ OUTBOX_MESSAGE : publishes_from

    VEHICLE {
      string vehicle_id PK
      string manufacturer
      string model
      string catalogue_status
    }
    QUOTE {
      string quote_id PK
      string vehicle_id
      decimal monthly_amount
      string currency
      int duration_months
      string pricing_policy_version
      datetime valid_until
    }
    OFFER {
      string offer_id PK
      string quote_id
      string status
      datetime created_at
      datetime expires_at
    }
    CUSTOMER {
      string customer_id PK
      string contact_reference
    }
    ORDER {
      string order_id PK
      string customer_id
      string offer_id
      string status
      string idempotency_key
      datetime submitted_at
    }
    CONTRACT {
      string contract_id PK
      string order_id
      int version
      string document_reference
      string status
    }
    ORDER_STATUS_HISTORY {
      string history_id PK
      string order_id
      string previous_status
      string new_status
      datetime occurred_at
    }
    OUTBOX_MESSAGE {
      string event_id PK
      string aggregate_id
      string event_type
      int schema_version
      datetime occurred_at
      datetime published_at
    }
~~~

This is a conceptual relationship view. In a strict microservice design, these entities do not all live in one shared database. Each bounded context persists its own model. The diagram shows business relationships, not permission for cross-service foreign keys.

## 4. Ownership and database boundaries

A possible physical allocation is:

- Catalogue DB: vehicle catalogue records.
- Pricing DB: pricing policy versions and quote snapshots.
- Offer DB: offer state and references to quote IDs.
- Customer DB: customer profile and contact data.
- Order DB: order aggregate, status history, idempotency records and Outbox.
- Contract DB: contract metadata, version and document storage references.
- Notification DB: delivery attempts and provider references.

This is a target decomposition, not a recommendation to create seven databases immediately. Several logical databases can share a PostgreSQL cluster initially, with separate credentials and migration ownership.

## 5. Important modelling choices

### Snapshot commercial terms

An accepted order should not depend on recalculating an old offer using today's pricing policy. Persist or reference an immutable quote snapshot containing the commercial terms accepted, policy version, validity and relevant inputs.

### Identity across services

Use stable opaque identifiers such as `vehicleId`, `offerId`, `customerId` and `orderId`. A service may store another service's identifier as a reference, but should not query or update the other service's tables.

### Order state transitions

Model allowed transitions explicitly, for example:

~~~mermaid
stateDiagram-v2
    [*] --> Draft
    Draft --> Submitted
    Submitted --> UnderReview
    UnderReview --> Accepted
    UnderReview --> Rejected
    Accepted --> ContractPending
    ContractPending --> ContractReady
    ContractReady --> Completed
    Submitted --> Cancelled
    UnderReview --> Cancelled
~~~

This is illustrative. Real transitions require business policy, actor permissions, cancellation rules and handling of late partner responses.

### Money

Represent money with an amount and currency. Avoid floating-point arithmetic for authoritative financial calculations. Use integer minor units or a decimal-safe representation with explicit rounding rules and currency precision.

### Time and validity

Distinguish the time a fact occurred, the time it was recorded and the period during which an offer is valid. Use timezone-aware timestamps at system boundaries and define the business timezone for date-only rules.

## 6. Data protection

Customer and contract information is sensitive. Keep personal data out of broad integration events, traces and logs. Prefer opaque references and fetch protected details from the owning context under authorization. Define retention and deletion rules before adding personal fields.

## 7. Questions to resolve before implementing the schema

- Is an Offer immutable after publication, or can it be revised?
- Does a quote expire independently from an offer?
- Which commercial inputs must be retained for audit?
- Can one customer submit multiple orders for the same offer?
- Which state transitions require a human decision?
- Which service is authoritative for customer identity?
- What is the contract/document retention policy?
- Which events must be delivered to downstream partners?

A senior design makes these questions visible before committing to migrations.