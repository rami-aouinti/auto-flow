# ADR-017 — Versioned pricing policies and immutable quote snapshots

- **Status:** Proposed for review with the Pricing Service readiness branch.
- **Date:** 2026-11-04
- **Owners:** AutoFlow Pricing
- **Related:** ADR-016 (security and API platform), ADR-015 (RoadRunner runtime), Wiki 12 (Pricing Service domain).

## Context

Pricing calculates lease quote snapshots that are consumed by Offer. The result must remain explainable after commercial guardrails change. A quote must not silently change when a policy is edited, replaced or retired. Tenant isolation must be enforced both in the application and in PostgreSQL.

## Decisions

1. **Pricing owns policy and quote data.** Offer calls Pricing over the documented HTTP API (and the versioned gRPC contract where a consumer explicitly supports it). Offer must not read Pricing tables directly.
2. **Policies are immutable versions.** Each tenant has monotonically increasing policy versions with a draft → active → retired lifecycle. At most one policy may be active per tenant. Publishing a newer version retires the previous active version; retired versions are never reactivated.
3. **Policy changes are append-only.** A policy's tenant, version, guardrails, creator, creation timestamp, validity and calculation algorithm cannot be edited after creation. PostgreSQL triggers enforce immutable policy fields and lifecycle transitions in addition to domain validation.
4. **Quotes are immutable snapshots.** Persist original lease inputs, calculated monetary values, calculation algorithm version, creation/expiry timestamps, and the exact policy ID/version used. A database trigger prevents changing snapshot fields. Soft deletion is the only supported update to a quote row.
5. **Quote validity defaults to seven days.** An active policy may set validity from 1 to 90 days. Existing tenants without an active policy retain the seven-day default during rollout. Retrieval of an expired quote returns HTTP 410; it does not recalculate or mutate the quote.
6. **Algorithm versions must be real.** Only `lease-annuity-v1` is accepted until another calculation implementation exists and has its own tests. A policy cannot claim a future algorithm version while the service still executes the v1 formula.
7. **Tenant isolation is defense in depth.** Repositories set `app.tenant_id` transaction-locally. PostgreSQL RLS is enabled and forced, and the application connects with the restricted `autoflow_pricing` role (not a superuser and not BYPASSRLS). Owner filtering is applied inside the tenant boundary; explicit scopes may widen owner access only within the same tenant.
8. **Synchronous calculation remains synchronous.** Offer requires a result before returning its response, so it uses the Pricing HTTP endpoint. RabbitMQ/Temporal are not inserted into this request path. They remain suitable for asynchronous downstream workflows, not for a synchronous quote calculation.
9. **Migrations are forward-only and tracked.** Versioned SQL migrations are applied by `apps/pricing-service/bin/migrate.sh`, which records filenames in `pricing_schema_migrations`. Existing Docker volumes are migrated explicitly; deleting a volume is not a migration strategy.
10. **Observability is low-cardinality.** Calculation count, outcome and duration may be recorded. Tenant IDs, user IDs, tokens and request payloads must not be metric labels or log fields.

## Consequences

- A quote can be audited against the exact inputs, policy and algorithm that produced it.
- Policy publication is transactional: retiring the previous active policy and activating the new version either both commit or both roll back.
- Database immutability and RLS integration tests require PostgreSQL and must run with the real restricted application role.
- Adding a calculation algorithm requires an implementation, domain tests, policy validation update, migration and contract/documentation update.
- The current annuity calculation remains illustrative. It is not a regulated APR disclosure, lender commitment or legally binding financing offer.

## Verification required before merge

- Unit tests for policy lifecycle, monotonically increasing versions, algorithm validation and quote expiry.
- PostgreSQL integration tests for tenant RLS, owner isolation, policy/quote immutability and soft deletion.
- REST validation/error tests and protobuf serialization compatibility tests.
- CI container build, Composer validation, static analysis and OpenAPI generation.
- Local Docker verification against the existing persistent PostgreSQL volume without deleting it.
- Explicit review of OTLP metrics exporter configuration; local Compose currently disables metric export.
