# 12 — Pricing Service: domain, persistence and delivery

## 1. Responsibility and boundaries

Pricing owns the calculation of lease instalments and the immutable quote record produced by that calculation. It is the authoritative owner of pricing calculations and quote history.

Pricing does not own:
- Vehicle identity or technical specifications (Vehicle Catalog).
- Commercial offer lifecycle and customer-facing publication (Offer).
- Customer profile, order, contract or delivery lifecycle.
- Direct access to another service's database.

Other services integrate through Pricing's HTTP or gRPC contracts. They must not read Pricing tables directly.

The current calculation is an illustrative fixed-rate annuity with a residual/balloon amount. It is not a regulated APR disclosure, lender commitment or legally binding offer. Day-count conventions, taxes, fees, insurance, maintenance, mileage, commissions and partner-specific rules require explicit product and legal decisions before they can be represented as production financial terms.

## 2. Current implementation

The following capabilities exist in the repository:

### Domain
- `Money`: integer minor units and ISO-like three-letter currency code.
- `LeaseTerms`: validates currency consistency, down-payment and residual-value bounds, duration and annual rate.
- `LeasePaymentCalculator`: deterministic annuity calculation with a separate zero-interest path.
- `PricingQuote`: immutable calculation result with tenant, creator, input-derived result and calculation version.

### Application
- `CalculateQuoteCommand` and `CalculateQuoteHandler`.
- Quote identifier generation through an application output port.
- Repository output port used to persist the quote after calculation.
- Read, list and soft-delete use cases.

### Infrastructure
- PostgreSQL PDO connection factory and repository adapter.
- Tenant context is applied transaction-locally with `set_config('app.tenant_id', ..., true)`.
- Repository operations run within tenant-scoped transactions.
- PostgreSQL row-level security is enabled and forced on `pricing_quotes`.
- Quote ownership is represented by `created_by`; read/delete-all scopes can widen owner access inside the current tenant.

### HTTP API
All protected routes require a verified bearer token and tenant context.

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/pricing/quotes` | Calculate and persist a quote |
| GET | `/api/pricing/quotes/{quoteId}` | Retrieve a quote within tenant/owner boundary |
| GET | `/api/pricing/quotes` | List quotes with limit/offset pagination |
| DELETE | `/api/pricing/quotes/{quoteId}` | Soft-delete a quote within tenant/owner boundary |
| GET | `/health` | Process liveness |
| GET | `/api/pricing/{vehicleId}` | Deprecated static demo compatibility endpoint |

Money values are decimal strings in JSON, never floating-point JSON numbers. Quote responses use `Cache-Control: no-store`.

### gRPC
The RoadRunner gRPC server exposes `PricingService.CalculateQuote` using the versioned protobuf contract under `pricing.v1`. The gRPC adapter authenticates the request, checks the `pricing:quote` scope and delegated tenant, maps validation failures to `INVALID_ARGUMENT`, authentication failures to `UNAUTHENTICATED`, authorization failures to `PERMISSION_DENIED`, and JWKS outages to `UNAVAILABLE`.

Offer currently uses the HTTP adapter and delegated-token flow. Keep HTTP as the active Offer integration until a generated PHP gRPC client and runtime are explicitly selected, installed, tested and benchmarked.

## 3. HTTP request and response

`POST /api/pricing/quotes`

```json
{
  "vehicleId": "vehicle-001",
  "vehiclePrice": "30000.00",
  "downPayment": "3000.00",
  "residualValue": "9000.00",
  "currency": "EUR",
  "durationMonths": 36,
  "annualInterestRateBasisPoints": 500
}
```

Money fields must be decimal strings with at most two fractional digits. The response contains `quoteId`, `vehicleId`, monthly and total amounts, residual value, currency, duration, annual rate in basis points and `calculationVersion`.

The current domain validation allows durations from 12 to 72 months and nominal annual rates from 0 to 2,000 basis points. These are demonstration guardrails, not approved commercial policy. Do not widen or present them as product rules without a business decision.

## 4. Calculation model and rounding

Let P be vehicle price, D down payment, R residual value, n number of monthly instalments and r the nominal annual rate divided by 12.

For zero interest:

```text
monthly = (P - D - R) / n
```

For positive interest:

```text
monthly = (P - D - R / (1 + r)^n) * r / (1 - (1 + r)^(-n))
```

Inputs are represented in integer minor units. The monthly amount is rounded once to the nearest minor unit using half-away-from-zero. Total instalments are derived from the rounded monthly amount, ensuring the displayed monthly amount multiplied by the number of instalments equals the displayed total.

The current `Money` implementation assumes two fractional digits for every currency. Before enabling currencies with zero or three minor-unit digits, introduce a currency-exponent policy and migrate parsing, formatting, persistence and tests together.

## 5. Persistence and tenant isolation

The service-owned table is `pricing_quotes`. It stores the calculated amounts in integer minor units, currency, duration, annual interest basis points, calculation version, tenant, creator, creation time and soft-delete timestamp.

The database role is `autoflow_pricing`. It must not be a superuser and must not have `BYPASSRLS`. RLS is both enabled and forced. The tenant policy compares `tenant_id` with transaction-local `current_setting('app.tenant_id', true)`.

The application repository sets the tenant context within the same transaction as each query. Never set a session-wide tenant variable on a pooled or long-lived connection. A missing tenant context must expose zero rows and reject writes.

The following checks have been executed against the local Docker database:
- A quote created through Offer → Pricing returned HTTP 200 and was persisted with tenant `tenant-demo`.
- The quote was visible to the `autoflow_pricing` role with tenant context `tenant-demo`.
- The same role saw zero rows for that quote with tenant context `tenant-other`.
- PostgreSQL reported both `relrowsecurity` and `relforcerowsecurity` as true.

These are local verification results, not a substitute for repeatable CI integration tests.

### Existing PostgreSQL volumes

Docker's `docker-entrypoint-initdb.d` scripts run only when the data directory is initialized for the first time. Adding a migration file later does not cause an existing volume to apply it. Apply new migrations explicitly and safely; do not delete the PostgreSQL volume to force initialization.

The current SQL migrations are:
- `Version20261104000100CreatePricingQuotes.sql`
- `Version20261104000200AddPricingQuoteSoftDelete.sql`
- `Version20261104000300AddPricingQuoteOwner.sql`
- `Version20261104000400AddQuoteSnapshotAndExpiry.sql`
- `Version20261104000500CreatePricingPolicies.sql`
- `Version20261104000600LinkQuotesToPricingPolicy.sql`
- `Version20261104000700EnforceImmutableQuoteSnapshots.sql`
- `Version20261104000800EnforceImmutablePricingPolicies.sql`
- `Version20261104000900ConstrainPricingCalculationVersion.sql`

Use `apps/pricing-service/bin/migrate.sh` with `DATABASE_ADMIN_URL` to apply unapplied versioned migrations. The runner records applied filenames in `pricing_schema_migrations`. The optional `PRICING_BOOTSTRAP_LOCAL_ROLE=1` switch is for local development only; production database roles must be provisioned securely out of band.

Deterministic demo data lives in `apps/pricing-service/fixtures/pricing_quotes_demo.sql`. Reusable PHPUnit fixture factories live in `apps/pricing-service/tests/Fixtures/`.

For local Docker development, apply pending migrations from the repository root with `DATABASE_ADMIN_URL` set to the PostgreSQL administrator connection, then seed the Swagger demo data with `docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U autoflow -d autoflow < apps/pricing-service/fixtures/pricing_quotes_demo.sql`. Do not remove the persistent PostgreSQL volume to apply migrations.

The role password in the role-creation SQL and `PRICING_DATABASE_URL` must remain aligned. Keep local credentials out of production configuration and inject production secrets through the deployment secret manager.

## 6. Security requirements

- Validate JWT issuer, signature, expiry and audience according to the shared CoreBundle contract.
- Require `pricing:quote` for quote calculation and access.
- Require a tenant delegated by the authenticated principal; never trust an arbitrary tenant header without CoreBundle validation.
- Default read/delete operations to the authenticated subject. Only explicit privileged scopes may widen access within the same tenant.
- Keep RLS enabled and forced; application checks are additional controls, not replacements for RLS.
- Never log bearer tokens, customer payloads or database credentials.
- Quote endpoints must remain non-cacheable.
- Return generic server errors; do not expose SQL, stack traces or internal infrastructure details.

## 7. Pricing policy and quote lifecycle — implementation in progress on the feature branch

The production-readiness branch now contains the first implementation slice:

- `PricingPolicy` is a tenant-scoped, versioned domain model with draft, active and retired states.
- `CreatePricingPolicyHandler` creates drafts; `PublishPricingPolicyHandler` activates a version.
- `PostgresPricingPolicyRepository` persists and reads policies under transaction-local tenant context and PostgreSQL RLS.
- Only one active policy per tenant is allowed. Publishing a new version retires the previous active version.
- Quote calculation loads the active tenant policy when one exists and checks the duration/rate guardrails. Existing tenants without a policy retain the legacy 7-day default while policy rollout is completed.
- Quotes persist original vehicle price/down payment, creation and expiry timestamps, and the exact policy ID/version used. The database trigger rejects mutation of snapshot fields while allowing soft deletion.
- The gRPC response adds fields for creation/expiry timestamps and policy ID/version without renumbering or changing existing protobuf fields; a serialization contract test covers the additive metadata.
- `/health` is the liveness endpoint; `/ready` checks PostgreSQL. The Pricing container healthcheck uses `/ready`, so dependent services wait for database readiness.
- Quote validity defaults to seven days and may be configured by policy (1–90 days). The API returns HTTP 410 for an expired quote lookup.
- REST calculation records low-cardinality outcome counters and request-duration histograms through OpenTelemetry. Compose enables the OTLP metrics exporter, Alloy receives OTLP over HTTP, converts metrics to Prometheus format and remote-writes them to Prometheus (`/api/v1/write`); Prometheus remote-write receiver is enabled. The quote stores `policy_id` and `policy_version`, and the database enforces a tenant-consistent policy reference. Only `lease-annuity-v1` is currently supported as a calculation algorithm; policies cannot advertise a future algorithm until its implementation and tests exist.

Current demo policy guardrails are intentionally aligned with the existing LeaseTerms boundaries (12–72 months and 0–2000 basis points). These are implementation defaults, not approved commercial or regulatory financing terms. The policy does not introduce taxes, fees, insurance, maintenance, mileage or lender-specific rules.

The API contract examples and local Swagger definition use the deterministic demo quote in `apps/pricing-service/fixtures/pricing_quotes_demo.sql`. PHPUnit fixtures are provided by `PricingQuoteFixture` and `PricingPolicyFixture`.

**Verification status:** unit tests cover policy lifecycle/versioning, quote expiry, policy-derived validity, and REST request validation/error responses. PostgreSQL integration tests cover tenant RLS, quote/policy immutability and repository behavior. The GitHub Actions runs and local Docker/PostgreSQL execution must pass before merge. Do not treat source creation as test evidence.

A quote is a historical calculation snapshot. Updating or retiring a policy must never change an existing quote.

## 8. Quality and operational acceptance criteria

Before calling Pricing production-ready, CI must verify:

1. Unit tests for Money, LeaseTerms and LeasePaymentCalculator, including boundaries, overflow and rounding.
2. Application tests for quote creation and repository failures.
3. HTTP functional tests for success, malformed JSON, missing/wrongly typed fields, invalid domain terms, authentication, scope and tenant errors.
4. PostgreSQL integration tests using the non-owner application role, covering tenant read isolation, cross-tenant insert rejection, owner isolation, soft-delete and transaction-local tenant context.
5. gRPC tests for successful calculation and status-code mapping.
6. OpenAPI and protobuf compatibility checks.
7. Static analysis, coding standards, dependency audit and container build.
8. Readiness checks for PostgreSQL separately from process liveness.
9. Metrics for calculation count, latency and error class, without high-cardinality tenant/user labels.
10. Backup/restore and load-test procedures.

## 9. Explicit non-goals until requirements exist

Do not invent customer-specific discounts, campaign stacking, VAT/tax calculation, insurance, maintenance, mileage charges, lender fees, residual-value prediction or credit scoring. These require product, legal and data-source decisions and should be introduced as separate policy/rule capabilities with documented ownership.

## 10. Implementation sequence

1. Close API validation, error contract, health/readiness and automated test gaps.
2. Implement versioned PricingPolicy persistence and administration use cases.
3. Add immutable quote snapshots, validity metadata and expiry semantics.
4. Complete REST/OpenAPI and gRPC contract tests.
5. Add operational metrics, deployment checks, migration automation and load/restore verification.

The feature-branch documentation describes implemented source behavior, but the branch remains unverified until its queued CI and local Docker/PostgreSQL checks complete. Do not merge or describe the service as production-ready before that evidence is green.
