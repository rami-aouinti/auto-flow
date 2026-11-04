# AutoFlow Vehicle Catalog Service

Authoritative catalogue for manufacturer identity, model lines, generations, vehicle variants and technical specifications. This service answers **what vehicle is this?** It does not own lease prices, customer discounts, commercial availability, orders or delivery dates.

## Local runtime

The service is a standalone Symfony 7.4 / PHP 8.4 application running on RoadRunner.

- API: http://localhost:8084
- Swagger UI (development only): http://localhost:8084/api/doc
- OpenAPI JSON (development only): http://localhost:8084/api/doc.json
- Liveness: http://localhost:8084/health/live
- Readiness: http://localhost:8084/health/ready

Start it from the repository root:

```bash
docker compose up -d --build vehicle-catalog-service vehicle-catalog-outbox-publisher
docker compose ps vehicle-catalog-service
```

## Domain model and ownership

- Manufacturer owns stable catalogue identity, normalized code and display name.
- Model belongs to one Manufacturer by ID.
- Generation belongs to one Model and may declare a known production year range.
- VehicleVariant belongs to one Generation and owns technical configuration.
- VehicleMediaReference values are references only; binary assets and delivery belong to Media.

Identifiers are opaque 128-bit random hexadecimal values. A manufacturer-provided variant code is optional metadata and is not the canonical identifier. Measurements use explicit units in field names: kW, cm³, mm, kg and g/km. Fuel, powertrain, body-style and transmission values are controlled vocabularies documented by the API/domain code.

Catalogue status is draft, published, discontinued or archived. New entries are draft. The catalogue-management scope is required to create entries; publishing a variant also publishes its non-archived parent hierarchy. Public read queries return published entries only.

Pricing, discounts, monthly instalments, customer eligibility, inventory/stock, delivery dates and order state are deliberately excluded. Other contexts store opaque catalogue IDs and their own historical snapshots; they never create cross-database foreign keys.

## Authentication and authorization

All `/api/*` endpoints require a Keycloak RS256 bearer token validated by CoreBundle against the `autoflow-catalog` audience.

- `catalog:read`: list manufacturers, search vehicles and read vehicle details.
- `catalog:manage`: register manufacturers, models, generations and variants.
- `catalog:manage`: publish a draft variant.

The local Keycloak demo user receives read access only. Grant `catalog:manage` to a trusted catalogue administrator role in the local realm or your environment. Do not grant it to ordinary customer clients. Health and development documentation routes are excluded from JWT authentication. This global catalogue is not tenant-owned; tenant-specific pricing and offers remain in their respective contexts.

## REST API v1

| Method | Path | Scope | Description |
| --- | --- | --- | --- |
| GET | `/api/v1/manufacturers` | catalog:read | Published manufacturers |
| GET | `/api/v1/vehicles` | catalog:read | Published variants with pagination and filters |
| GET | `/api/v1/vehicles/{variantId}` | catalog:read | Published variant detail |
| POST | `/api/v1/manufacturers` | catalog:manage | Create a draft manufacturer |
| POST | `/api/v1/models` | catalog:manage | Create a draft model |
| POST | `/api/v1/generations` | catalog:manage | Create a draft generation |
| POST | `/api/v1/vehicles` | catalog:manage | Create a draft technical variant |
| POST | `/api/v1/vehicles/{variantId}/publish` | catalog:manage | Publish variant and non-archived parents |

Vehicle list filters: `q`, `manufacturerId`, `modelId`, `generationId`, `fuelType`, `powertrain`, `bodyStyle`, `transmission`, `minPowerKw`, `maxPowerKw`, `minSeats`, `maxSeats`, `limit` (1-100) and `offset` (0+).

Errors use `application/problem+json`. Invalid data returns 422, malformed pagination/filters 400, duplicate canonical identities 409, unknown parent references 422 and invisible/missing variants 404. Internal SQL and exception details are never returned.

## PostgreSQL and fixtures

PostgreSQL is authoritative. The service uses the dedicated `autoflow_catalog` login and service-owned tables. Apply the migration and load deterministic fictional examples:

```bash
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/vehicle-catalog-service/migrations/00_create_catalog_database_role.sql
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/vehicle-catalog-service/migrations/Version20261105000100CreateVehicleCatalog.sql
docker compose exec vehicle-catalog-service php bin/load-fixtures.php
```

Fresh Docker volumes execute the role, schema and fixture SQL through the PostgreSQL entrypoint automatically. The fixture contains Volkswagen, BMW, Mercedes-Benz and Audi demo records. These are explicitly illustrative local data, not scraped or licensed catalogue data.

## Integration events and Outbox

Every catalogue write and its versioned integration event are committed in one PostgreSQL transaction. The Outbox publisher claims rows with `FOR UPDATE SKIP LOCKED`, publishes persistent RabbitMQ messages with publisher confirms to the durable `autoflow.catalog.events` topic exchange, then marks the event published. Failed publication is unlocked and retried with bounded exponential backoff. Events contain stable event/aggregate IDs, type, schema version, occurrence time and a minimal payload.

Vehicle Search will own its Elasticsearch projection and consume these events idempotently. This service does not write to Elasticsearch and does not claim that the future Search consumer already exists.

## Quality

Run unit tests in the service container:

```bash
docker compose run --rm --no-deps vehicle-catalog-service php vendor/bin/phpunit --configuration phpunit.xml.dist
```

The CI pipeline validates the Composer manifest, boots/builds the Symfony service, runs domain tests, verifies the API OpenAPI document and runs the repository quality gates. PostgreSQL integration tests are enabled when `CATALOG_TEST_DATABASE_URL` is supplied.

## Architecture

Domain and Application code are framework/persistence independent. Infrastructure owns PDO/PostgreSQL and RabbitMQ adapters. Presentation owns HTTP DTO mapping and error translation. CoreBundle is used only for JWT verification, scope authorization, correlation IDs and readiness checks. See Wiki chapters 03, 05, 06, 13, 15 and 16 and ADR-017.
