# 03 — Domain and application design

## 1. Domain-Driven Design

Domain-Driven Design (DDD) helps model complex business domains through shared language and explicit boundaries. DDD is not a folder structure and does not require microservices.

### Strategic DDD

- **Ubiquitous Language:** shared terms used by domain experts and developers.
- **Bounded Context:** a boundary within which a model and its language have precise meaning.
- **Context Map:** describes relationships and integration between contexts.
- **Subdomain:** a business problem area, often core, supporting or generic.

Potential AutoFlow contexts include Catalogue, Pricing, Offer, Customer, Order, Contract and Notification. They are candidates to validate, not a predetermined service count.

### Tactical DDD

- **Entity:** identity and lifecycle matter, such as an Order.
- **Value Object:** immutable value defined by attributes, such as Money or VehicleId.
- **Aggregate:** consistency boundary through which invariants are enforced.
- **Repository:** domain-oriented abstraction for aggregate persistence.
- **Domain Service:** business operation that does not naturally belong to one entity/value object.
- **Domain Event:** a meaningful fact that has occurred.

An aggregate is not simply every Doctrine entity connected by relations. It is a deliberate consistency boundary.

## 2. Clean, Hexagonal and Onion Architecture

These approaches are related but not identical:

- **Clean Architecture** emphasizes dependency direction: code dependencies point inward toward policy and business rules.
- **Hexagonal Architecture (Ports and Adapters)** describes communication with the outside world through ports and replaceable adapters.
- **Onion Architecture** is another formulation that places domain policy at the centre.

They can be combined. The goal is not to create folders for their own sake, but to protect business policy from infrastructure details.

## 3. Recommended service structure

~~~text
src/
  Domain/
    Entity/
    ValueObject/
    Event/
    Repository/
    Service/
    Exception/
  Application/
    Command/
    Query/
    Handler/
    DTO/
    Port/
  Infrastructure/
    Persistence/
    Messaging/
    Http/
    Observability/
    External/
  Presentation/
    Http/
      Controller/
      Request/
      Response/
~~~

This is a recommended structure, not a claim that every directory already exists in both demo services.

### Domain

Contains business concepts and invariants. It should not depend on Symfony, Doctrine, HTTP, RabbitMQ or external SDKs.

### Application

Coordinates use cases: what the system does, in what order, and which ports it needs. A ComposeOffer use case can request inputs through ports and coordinate pricing without containing HTTP request handling.

### Infrastructure

Implements technical details behind ports: Doctrine repositories, HTTP clients, RabbitMQ/Messenger transports, Redis adapters, Elasticsearch indexing and telemetry exporters.

### Presentation

Translates external input into application calls and maps results into transport responses. Controllers should remain thin.

## 4. Dependency rule

~~~text
Presentation ───────┐
Infrastructure ─────┼──> Application ───> Domain
                    │
                    └── implements ports defined inward
~~~

Domain must not import framework or infrastructure classes. Application depends on Domain and abstractions it owns. Infrastructure implements those abstractions. Presentation invokes Application. Dependency Injection wires implementations at the edge.

## 5. SOLID in practical terms

- **Single Responsibility:** one coherent reason to change. Avoid controllers that validate, calculate, persist, send email and call partners.
- **Open/Closed:** extend behaviour through stable abstractions when variation is expected; avoid needless abstraction.
- **Liskov Substitution:** implementations of a port honour its behavioural contract.
- **Interface Segregation:** consumers depend only on capabilities they use.
- **Dependency Inversion:** high-level business policy depends on abstractions, not low-level infrastructure.

SOLID does not require an interface for every class.

## 6. Commands, queries and CQRS

A command asks the system to change state; a query asks for information without changing business state.

Separating command and query models can clarify complex systems. Full CQRS, separate databases and event sourcing are not automatic consequences. Introduce them only when read/write needs, scaling or audit requirements justify the cost.

## 7. Validation boundaries

Distinguish transport validation (malformed JSON and types), application validation (use-case preconditions and authorization), domain invariants (rules that must always hold) and persistence constraints (final integrity protection).

A controller-only validation rule cannot protect an invariant if a message consumer or CLI invokes the same use case.

## 8. Testing implications

- Domain unit tests: fast, no framework boot.
- Application tests: verify orchestration through controlled ports.
- Infrastructure integration tests: real database, broker or search engine where useful.
- API/contract tests: verify external behaviour and compatibility.
- End-to-end tests: verify a small number of critical journeys.

Seek confidence at the lowest reasonable test level, not maximal mocking.