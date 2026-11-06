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

### List offers

`GET /api/offers`

Requires `offers:read`. Returns only offers created by the authenticated subject in the current tenant. Supports optional query parameters:

- `status` — one of `draft`, `published`, `accepted`, `rejected`, `expired` or `withdrawn`.
- `search` — partial match against offer ID, quote ID or vehicle ID (maximum 100 characters).
- `limit` — page size from 1 to 100 (default 20).
- `offset` — zero-based result offset (default 0).

The response contains `items`, `total`, `limit` and `offset`, ordered newest first. Reads are tenant-isolated by PostgreSQL row-level security and additionally restricted to the offer creator.

### Retrieve an offer

`GET /api/offers/{offerId}`

Requires `offers:read`. The lookup is restricted to the authenticated tenant and offer creator.

### Read an offer audit history

`GET /api/offers/{offerId}/history`

Requires `offers:read`. Returns the append-only lifecycle audit events for an offer owned by the authenticated subject in the current tenant. Each event includes its type, previous and resulting status, actor type, timestamp and metadata. The endpoint returns HTTP 404 for offers outside the caller's tenant/ownership boundary.

The `offer_audit_events` table is protected by forced tenant row-level security. The application database role has SELECT and INSERT only; UPDATE, DELETE and TRUNCATE are revoked. Audit inserts occur in the same PostgreSQL transaction as the offer write. The migration backfills lifecycle facts whose timestamps are already stored.

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

The aggregate records transition timestamps (`publishedAt`, `acceptedAt`, `rejectedAt`, `expiredAt`) as well as these domain facts:

- `OfferCreated`
- `OfferPublished`
- `OfferExpired`

The repository persists the Offer state and its pending domain events in one PostgreSQL transaction. Events are stored in `offer_outbox_events` with a versioned envelope containing `eventId`, `eventType`, `schemaVersion`, `occurredAt`, `producer`, `tenantId`, aggregate identity and event data.

The `offer-outbox-publisher` publishes durable messages to the RabbitMQ topic exchange `autoflow.events` with routing keys `offer.created`, `offer.published` and `offer.expired`. It declares the durable queue `offer.events` and binds `offer.#` as a catch-all integration buffer. Publisher confirms are awaited before an outbox row is marked as published. Failed attempts use exponential backoff capped at five minutes.

**Current local topology:** the Compose stack does not run a consumer for `offer.events`. It is therefore expected to retain ready messages until a downstream integration is deployed. This queue is a broker-side buffer, not an event store, an archive, or the Notification delivery queue. Notification has its own queue and consumes only `offer.published`. Do not purge `offer.events` to make its depth zero. Each future consumer should own a dedicated durable queue and bind only the event routing keys it handles; it must deduplicate by `eventId` and make its handler idempotent.

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

If the PostgreSQL volume already exists, Docker does not rerun `docker-entrypoint-initdb.d`. Apply both new migrations once manually from the repository root (the role and offers table should already exist):

```bash
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/offer-service/migrations/Version20261104000500CreateOfferOutbox.sql
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/offer-service/migrations/Version20261104000600AddOfferLifecycleTimestamps.sql
```

Apply the append-only offer audit migration once to the existing volume:

```bash
docker compose --env-file .env.example exec -T postgres psql -U autoflow -d autoflow < apps/offer-service/migrations/Version20261106000700CreateOfferAuditEvents.sql
```

Then rebuild and start the Offer API and its worker container:

```bash
docker compose --env-file .env.example up -d --build offer-service offer-workers
```

The `offer-workers` Compose service runs both `offer-expiration` and `offer-outbox-publisher` under Supervisor. The expiration process needs PostgreSQL; the publisher additionally waits for RabbitMQ to become healthy.

## Local demo fixtures and Swagger UI

The development-only fixture loader creates five deterministic offers for the local Keycloak demo user:

| Offer ID | Vehicle | Initial status |
| --- | --- | --- |
| `demo-offer-draft-001` | BMW 320d | `draft` |
| `demo-offer-published-001` | VW ID.4 Pro | `published` |
| `demo-offer-accepted-001` | BMW 330e | `accepted` |
| `demo-offer-rejected-001` | Audi A4 40 TDI | `rejected` |
| `demo-offer-expired-001` | Mercedes C 220 d | `expired` |

The loader is idempotent, requires `APP_ENV=dev`, and uses the `autoflow_offer` database role with the tenant RLS context set to `tenant-demo` by default. It requires the Keycloak user's actual `sub` claim so the existing owner-only authorization rule works. It does not create Outbox rows or publish RabbitMQ messages.

Enable the development-only Swagger UI for the Offer container by adding these values to the repository-root `.env` file:

```dotenv
OFFER_APP_ENV=dev
OFFER_APP_DEBUG=1
```

Recreate the Offer container (the service image must include the new loader):

```bash
docker compose up -d --build --force-recreate offer-service
```

Obtain the demo user's subject from a local access token and load the fixtures:

```bash
DEMO_SUBJECT=$(curl -fsS -X POST http://localhost:8080/realms/autoflow/protocol/openid-connect/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=password' \
  --data-urlencode 'client_id=autoflow-local-client' \
  --data-urlencode 'username=demo' \
  --data-urlencode 'password=demo' |
  python3 -c 'import sys,json,base64; token=json.load(sys.stdin)["access_token"]; part=token.split(".")[1]; print(json.loads(base64.urlsafe_b64decode(part + "=" * (-len(part) % 4)))["sub"])')

docker compose exec -e APP_ENV=dev -e DEMO_USER_SUBJECT="$DEMO_SUBJECT" \
  -e DEMO_TENANT_ID=tenant-demo offer-service php bin/load-demo-fixtures.php
```

Open [Offer Swagger UI](http://localhost:8081/api/doc). Click **Authorize** and paste the same Keycloak access token (Bearer). Use `GET /api/offers/{offerId}` with one of the IDs in the table to retrieve the corresponding persisted fixture. The documentation also includes request and response examples for the lifecycle endpoints.

To retrieve a fixture directly:

```bash
curl -i http://localhost:8081/api/offers/demo-offer-published-001 \
  -H "Authorization: Bearer $TOKEN"
```

Keep `OFFER_APP_ENV=dev` local only. The Swagger bundle and UI are intentionally not enabled in production.

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

The integration suite also includes a RabbitMQ publisher-confirm test. Start PostgreSQL and RabbitMQ, apply migrations `00`, `004`, `005` and `006`, then run:

```bash
OFFER_TEST_DATABASE_URL='postgresql://autoflow_offer:local-only-offer-password@127.0.0.1:5432/autoflow' \\
RABBITMQ_TEST_HOST=127.0.0.1 \\
RABBITMQ_TEST_PORT=5672 \\
RABBITMQ_TEST_USER=autoflow \\
RABBITMQ_TEST_PASSWORD=autoflow \\
  vendor/bin/phpunit --configuration phpunit.xml tests/Integration/Offer
```

The integration tests verify persisted state, atomic Offer/outbox writes, forced tenant RLS, optimistic concurrency, expiration processing and RabbitMQ publisher confirms. The audit migration is applied separately for the local history endpoint. PostgreSQL tests are skipped without `OFFER_TEST_DATABASE_URL`; the RabbitMQ publisher test is additionally skipped without `RABBITMQ_TEST_HOST`.
