# Offer Service

Offer Service owns the lifecycle of a commercial offer. It creates an offer from a Pricing quote, stores an immutable copy of the commercial terms, and manages offer publication.

## HTTP API

All API endpoints require a verified access token and the tenant context.

### Create an offer

`POST /api/offers/{vehicleId}`

Required JSON fields:

- `vehiclePrice` — decimal string with two fractional digits.
- `downPayment` — decimal string with two fractional digits.
- `residualValue` — decimal string with two fractional digits.
- `currency` — three-letter uppercase currency code.
- `durationMonths` — integer.
- `annualInterestRateBasisPoints` — integer.

Requires `offers:quote`. Pricing is calculated by Pricing Service. Offer Service stores the returned quote identifier and an immutable pricing snapshot. New offers start in `draft` state and are valid for 30 days by default.

### Retrieve an offer

`GET /api/offers/{offerId}`

Requires `offers:read`. The lookup is restricted to the authenticated tenant and offer creator.

### Publish an offer

`POST /api/offers/{offerId}/publish`

Requires `offers:quote`. Only a draft offer can be published, and it cannot be published at or after its expiration time.

The older `POST /api/offers/{vehicleId}/quotes` endpoint remains available as a quote-only compatibility endpoint; it does not create an Offer aggregate.

## Architecture

- `Domain`: Offer aggregate, status transitions, identifier and immutable pricing snapshot.
- `Application`: create, retrieve and publish use cases.
- `Infrastructure/Pricing`: HTTP adapter to Pricing Service, including delegated token exchange.
- `Infrastructure/Persistence`: PostgreSQL repository with tenant row-level security.
- `Controller`: HTTP input/output mapping and authorization scope declarations.

Offer Service owns its persistence role (`autoflow_offer`) and does not access Pricing tables. The quote is referenced by ID and copied into the Offer snapshot so that later Pricing changes do not rewrite an existing offer.

## Database setup

On a **new empty PostgreSQL volume**, the Compose initialization scripts create the Offer role and table automatically.

If the PostgreSQL volume already exists, Docker does not rerun `docker-entrypoint-initdb.d`. Apply the scripts once manually from the repository root:

```bash
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/offer-service/migrations/00_create_offer_database_role.sql
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/offer-service/migrations/Version20261104000400CreateOffers.sql
```

Then rebuild/recreate Offer Service so it receives the new database configuration:

```bash
docker compose up -d --build offer-service
```

## Tests

From the repository root:

```bash
composer dump-autoload
vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Offer
```
