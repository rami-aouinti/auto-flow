# Applications

Each bounded context is an independently deployable application. Customer, Offer, Pricing, Vehicle Catalog, Order, Payment and Billing own separate service boundaries and database access. See [architecture](../docs/architecture/overview.md).
- `fulfillment-service/` — vehicle preparation, delivery scheduling, carrier tracking and proof of delivery.
- `inventory-service/` — physical vehicle units, VIN registry, stock availability, location and stock movement history.
- `crm-service/` — leasing applications, eligibility reviews, sales-advisor assignment, tasks, customer interactions and CRM audit/outbox events.
