# Offer Service

Offer Service owns the lifecycle of a commercial offer. It creates an offer from a Pricing quote, stores an immutable copy of the commercial terms, and manages publication, acceptance, rejection and expiration.

## HTTP API

All API endpoints require a verified access token and tenant context. Offer reads and lifecycle transitions are restricted to the authenticated offer creator in the current tenant.

### Create an offer

`POST /api/offers/{vehicleId}`

Required JSON fields:

- `vehiclePrice` — decimal string with two fractional digits.
- `downPayment` — decimal string with two fractional digits.
- `residualValue` — decimal string with two fractional digits.
- `currency` — three-letter currency code.
- `durationMonths` — integer.
- `annualInterestRateBasisPoints` — integer.

Requires `offers:quote`. Pricing is calculated by Pricing Service. Offer Service stores the returned quote identifier and an immutable pricing snapshot. New offers start in `draft` state and are valid for 30 days by default.

### Retrieve an offer

`GET /api/offers/{offerId}`

Requires `offers:read`. The lookup is restricted to the authenticated tenant and offer creator.

### Publish an offer

`POST /api/offers/{offerId}/publish`

Requires `offers:quote`. Only a draft offer can be published, and it cannot be published at or after its expiration time.

### Accept an offer

`POST /api/offers/{offerId}/accept`

Requires `offers:quote`. Only a published offer can be accepted, and acceptance must happen before its expiration time.

### Reject an offer

`POST /api/offers/{offerId}/reject`

Requires `offers:quote`. Only a published offer can be rejected, and rejection must happen during its validity period.

Invalid lifecycle transitions return HTTP 409. Unknown offers and offers outside the caller's tenant/ownership boundary return HTTP 404.

**Authorization note:** write operations currently reuse the existing `offers:quote` scope so this branch does not modify Keycloak/JWT configuration while that work is being handled separately. A follow-up security change should introduce dedicated `offers:publish`, `offers:accept` and `offers:reject` scopes and assign them to the intended actors. The current owner-only access rule is intentionally conservative; supporting a distinct customer/recipient actor requires adding that identity to the Offer model and authorization policy.

The older `POST /api/offers/{vehicleId}/quotes` endpoint remains available as a quote-only compatibility endpoint; it does not create an Offer aggregate.

## Lifecycle

`draft -> published -> accepted`

`draft -> expired` and `published -> expired` are automatic when `expiresAt <= now`.

`published -> rejected` is allowed only before expiration. Accepted and rejected offers are terminal and are not expired later. Invalid transitions are rejected by the domain aggregate, not only by the controller. Lifecycle writes use optimistic status checks, so concurrent accept/reject/publish requests cannot overwrite a newer state; stale requests receive HTTP 409.

The `offer-expiration-worker` checks for due tenants every 30 seconds. It locks due offer rows with `FOR UPDATE SKIP LOCKED`, updates their status and inserts `OfferExpired` events in the same PostgreSQL transaction. Multiple worker instances can safely share the workload.

## Domain events and transactional outbox

The aggregate records these domain facts:

- `OfferCreated`
- `OfferPublished`
- `OfferExpired`

The repository persists the Offer state and its pending domain events in one PostgreSQL transaction. Events are stored in `offer_outbox_events` with a versioned envelope containing `eventId`, `eventType`, `schemaVersion`, `occurredAt`, `producer`, `tenantId`, aggregate identity and event data.

The `offer-outbox-publisher` publishes durable messages to the RabbitMQ topic exchange `autoflow.events`, routing keys `offer.created`, `offer.published` and `offer.expired`, and durable queue `offer.events`. Publisher confirms are awaited before an outbox row is marked as published. Failed attempts use exponential backoff capped at five minutes.

Delivery is **at least once**, not exactly once: a process can crash after RabbitMQ confirms a message but before PostgreSQL commits `published_at`. Consumers must deduplicate using the stable `eventId` and make their handlers idempotent. Event payloads deliberately avoid access tokens and full customer profiles.

## Pricing transport decision

The Offer application depends on `PricingGatewayInterface`; HTTP is the active adapter and continues to use the existing delegated-token flow and Pricing REST contract.

Pricing already exposes a RoadRunner gRPC server and a `.proto` contract. The Offer runtime currently has no generated gRPC client stub and the PHP image does not install the `grpc` extension/client runtime. Replacing HTTP now would therefore add a new runtime dependency and deployment risk without a demonstrated performance requirement. Keep HTTP as the production adapter for this iteration. Revisit a parallel gRPC adapter when the PHP gRPC client/runtime is selected and benchmarked; the application port keeps that change isolated.

## Architecture

- `Domain`: Offer aggregate, lifecycle invariants, identifier, immutable pricing snapshot and domain events.
- `Application`: create, retrieve, publish, accept, reject and expiration use cases.
- `Infrastructure/Pricing`: HTTP adapter to Pricing Service, including delegated token exchange.
- `Infrastructure/Persistence`: PostgreSQL repository with tenant row-level security and transactional outbox writes.
- `Infrastructure/Messaging`: RabbitMQ outbox publisher.
- `Controller`: HTTP input/output mapping and authorization scope declarations.

Offer Service owns its persistence role (`autoflow_offer`) and does not access Pricing tables. The quote is referenced by ID and copied into the Offer snapshot so that later Pricing changes do not rewrite an existing offer.

## Database setup

On a **new empty PostgreSQL volume**, the Compose initialization scripts create the Offer role, table, outbox table and expiration tenant lookup function automatically.

If the PostgreSQL volume already exists, Docker does not rerun `docker-entrypoint-initdb.d`. Apply the new migration once manually from the repository root (the role and offers table should already exist):

```bash
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/offer-service/migrations/Version20261104000500CreateOfferOutbox.sql
```

Then rebuild and start the Offer API and its two workers:

```bash
docker compose up -d --build offer-service offer-expiration-worker offer-outbox-publisher
```

The expiration worker needs PostgreSQL. The outbox publisher additionally waits for RabbitMQ to become healthy.

## Tests

Unit tests:

```bash
composer install --no-interaction
composer dump-autoload
vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Offer
```

PostgreSQL integration tests require a database where migrations `00`, `004` and `005` have been applied and a connection URL for the `autoflow_offer` role:

```bash
OFFER_TEST_DATABASE_URL='postgresql://autoflow_offer:local-only-offer-password@127.0.0.1:5432/autoflow' \
  vendor/bin/phpunit --configuration phpunit.xml tests/Integration/Offer
```

The integration tests verify persisted state, transactional outbox records, forced tenant RLS and the automatic expiration transaction. They are skipped when `OFFER_TEST_DATABASE_URL` is not set.
