# AutoFlow architecture

AutoFlow is an independent learning and interview portfolio project inspired by a digital
vehicle-leasing journey. It does not describe MeinAuto.de's internal systems.

## Principles

- Monorepo with independently deployable Symfony applications.
- Database per service; no cross-service database access or shared Doctrine entities.
- REST for synchronous interactions.
- RabbitMQ and Symfony Messenger for asynchronous messages.
- Temporal for durable long-running workflows.
- Transactional Outbox and idempotent consumers.
- OpenAPI contracts, automated tests and observability.

## Planned bounded contexts

Vehicle Catalog, Inventory, Offer, Pricing, Identity, Customer, Application, CRM, Financing,
Contract, Document, Order, Dealer Integration, Delivery, Notification and Audit.

The initial Docker foundation provides PHP 8.4 with Xdebug, PostgreSQL, RabbitMQ, Redis and
Mailpit. Business microservices will be added incrementally.

## CRM bounded context

CRM owns the sales application pipeline and advisor workspace. It references Customer, Offer, Vehicle and Quote identifiers without owning those records. The first implementation provides tenant-scoped application submission and lifecycle, eligibility review results, advisor assignment, tasks, interaction history, audit records and a transactional Outbox. Customer identity and offer references are opaque IDs at this boundary; authoritative cross-service validation and automated eligibility-provider integrations are subsequent integration work.
