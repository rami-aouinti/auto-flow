# 01 — Product vision and business domain

## 1. Product definition

AutoFlow Mobility Platform is a learning and portfolio platform for modelling a digital vehicle-leasing experience.

The goal is to demonstrate how a backend platform can coordinate a multi-step commercial process involving vehicle discovery, offer composition, pricing, customer information, contract preparation, order progression and customer communication.

The project is inspired by public-facing leasing journeys. It is an independent implementation and does not represent the internal systems or architecture of MeinAuto.de.

## 2. The business problem

A leasing journey is not a single CRUD operation. It can involve:

- Discovering a vehicle and evaluating its characteristics.
- Presenting an offer based on vehicle and commercial conditions.
- Collecting customer, contact and delivery information.
- Checking eligibility, availability and commercial rules.
- Preparing an order and contract.
- Coordinating payment, identity, document or partner systems where required.
- Communicating meaningful status changes to the customer.

The engineering challenge is to preserve business correctness while components collaborate, fail independently and evolve at different speeds.

## 3. Actors and capabilities

| Actor or capability | Responsibility |
| --- | --- |
| Customer | Explores vehicles, configures an offer and submits information |
| Sales / operations | Reviews exceptional or operational cases |
| Catalogue | Owns vehicle identity, specifications and catalogue information |
| Search | Provides filtering, indexing and discovery |
| Pricing | Owns pricing rules and calculation results |
| Offer | Composes a commercial proposal |
| Customer | Owns customer profile and contact information |
| Order | Owns order lifecycle and state transitions |
| Contract | Owns agreement documents, versions and lifecycle |
| Notification | Owns communication preferences, templates and delivery outcomes |
| External partners | Provide integration capabilities such as identity, payment or fulfilment |

These are business capabilities and candidate boundaries. They should not automatically become ten microservices. Service decomposition must be justified by ownership, change cadence, data boundaries, scaling and operational cost.

## 4. Illustrative customer journey

1. **Discover** — search and filter available vehicles.
2. **Evaluate** — inspect vehicle details and commercial information.
3. **Configure** — select relevant leasing parameters.
4. **Calculate** — obtain a price based on explicit inputs and pricing rules.
5. **Apply** — submit customer and delivery information.
6. **Validate** — perform business checks and resolve exceptions.
7. **Prepare** — create order and contract artefacts.
8. **Progress** — coordinate downstream operations and partner interactions.
9. **Communicate** — notify the customer about meaningful state changes.

This journey describes the domain vision. It does not claim that all steps are available through the current demo API.

## 5. Ubiquitous language

| Term | Meaning |
| --- | --- |
| Vehicle | A specific vehicle identity or catalogue entry |
| Offer | A commercial proposal presented to a customer |
| Quote | A price result calculated for explicit inputs and validity rules |
| Pricing policy | Rules used to calculate or select a price |
| Order | A submitted customer intent tracked through a lifecycle |
| Contract | The legally relevant agreement and its versions |
| Workflow | A durable process coordinating steps over time |
| Domain event | A fact that has happened within a business context |
| Integration event | A published contract intended for consumers outside its owner |

An Offer is not necessarily an Order. A Quote is not automatically a legally binding Contract. Precise language prevents incorrect models and APIs.

## 6. Business invariants to formalize

Examples that should be validated against final requirements:

- An order references the offer or commercial terms accepted by the customer.
- A quote records sufficient inputs and policy/version information to explain its result.
- A state transition is valid from the current order state.
- Repeated delivery of a command must not duplicate business effects.
- Contract versions remain traceable after changes.
- A notification failure must not silently reverse a successful business transaction.

## 7. Why domain-first design?

Technology-first decomposition often creates generic services with unclear ownership. Domain-first design asks:

- Which capability is responsible?
- Which business rules does it own?
- Which data is authoritative?
- Which events does it publish?
- Which decisions must remain inside its boundary?

This creates boundaries that can be explained to developers and business stakeholders alike.