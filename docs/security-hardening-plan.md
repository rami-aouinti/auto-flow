# Security hardening plan — implementation truth and production gates

This document is the operational companion to [ADR-016](adr/ADR-016-security-and-api-platform.md) and [the security architecture chapter](wiki/15-security-and-api-platform.md).

It intentionally distinguishes code already present in `master`, controls added to the security-hardening branch, and work that still requires runtime integration or deployment evidence. A checklist item is not complete merely because a configuration example or interface exists.

## Security controls already present in the repository

- Keycloak local realm and RS256 access-token validation through the shared CoreBundle.
- Issuer, algorithm, signature, audience and time-claim validation.
- Request-scoped authenticated principal, scope and tenant context.
- Offer/Pricing Token Exchange and Pricing audience isolation.
- Pricing quote tenant isolation through PostgreSQL RLS, a restricted database role, transaction-local tenant context and creator ownership.
- Pricing REST and gRPC authorization checks.
- Order Management tenant-scoped persistence, forced RLS, immutable snapshots and upstream reference validation.
- CoreBundle correlation propagation, gRPC metadata allow-listing and safe Problem Details helpers.

These controls must continue to be protected by negative tests. Do not weaken them to make a happy-path test pass.

## Security automation added in this branch

- `.github/workflows/security-audit.yml`: Composer advisory audit for every committed lockfile, Trivy filesystem vulnerability/misconfiguration/secret scan, and pull-request dependency review.
- `.github/workflows/codeql.yml`: scheduled and change-triggered CodeQL analysis for PHP.
- `.github/dependabot.yml`: weekly Composer, Docker and GitHub Actions update proposals.
- `SECURITY.md`: private vulnerability reporting and coordinated remediation policy.

The first successful run of each workflow is required before these controls can be called operational. Triage scanner findings; do not suppress a finding without recording why it is a false positive or accepted risk.

## Runtime and deployment work still required

### Identity and token lifecycle
- Implement a real tenant membership/selection capability before issuing a token for an active tenant. A `tenant_id` claim is trusted only because the issuer has verified membership; it is not itself proof of membership.
- Disable the local password-grant smoke-test client outside local development. Browser clients must use Authorization Code + PKCE with S256, exact redirect URI registration, state/nonce handling and secure token storage.
- Test JWKS cache expiry, key rotation, unknown `kid`, issuer outage, malformed claims, clock skew, token expiry and fail-closed behavior.
- Define production token lifetimes, refresh-token rotation/reuse detection where applicable, signing-key rotation and emergency credential rotation.
- Evaluate sender-constrained tokens (mTLS or DPoP) for high-risk external clients and workload credentials.

### Service identity and authorization
- Review every service-to-service edge, not just Offer → Pricing. Give each workload a distinct identity, audience and least-privilege scopes.
- Replace Order Service's direct forwarding of the inbound user bearer token to Offer/Pricing/Catalog with an explicit delegated-token policy. No fallback to a broad service token; fail closed if delegation fails.
- Ensure tenant delegation can only be performed by explicitly authorized workloads and that a caller-controlled tenant header cannot override a verified token claim.
- Add negative tests for missing/invalid token, wrong issuer, wrong audience, expired token, missing scope, unauthorized tenant delegation, cross-user access and cross-tenant access for every tenant-owned endpoint.
- Keep business authorization in the owning service. CoreBundle must remain a technical identity/principal component, not a global business-role engine.

### Tenant and data isolation
- Audit every tenant-owned table, repository, cache key, search index, object-storage path, event and background job.
- Add PostgreSQL RLS (or a documented equivalent boundary) to every tenant-owned persistence model. Use dedicated non-superuser, `NOBYPASSRLS` runtime roles.
- Verify that tenant context is set transaction-locally and cannot leak between pooled connections or RoadRunner workers. Test missing tenant, tenant switch, nested transactions, rollback and concurrent requests.
- Preserve both tenant isolation and resource ownership. Elevated tenant-wide permissions must be explicit, narrow and audited.
- Vehicle Catalog's current global reference dataset is intentionally not tenant-owned; if customer-specific catalog/pricing/availability data is introduced, define its ownership and isolation before adding endpoints.

### Network and API edge
- Integrate Traefik as the only public ingress in the production Compose/deployment profile. Do not expose service, database, broker, Redis, Keycloak admin or observability ports publicly.
- Terminate TLS at the edge and use TLS (and assess mTLS) for sensitive internal service connections. Configure trusted proxy hops explicitly; never trust arbitrary forwarded headers.
- Add Redis-backed rate limits for unauthenticated IP/client traffic and authenticated subject/tenant business operations. Test 429 and Retry-After behavior, replica consistency and Redis failure policy.
- Add explicit CORS allow-lists, secure response headers, request/body size limits, timeouts and route-specific methods/content types.
- Keep health/readiness endpoints minimal and non-sensitive. Protect documentation and administrative endpoints in non-development environments.

### Messaging and workflow security
- Provision distinct RabbitMQ users/vhosts and least-privilege configure/write/read permissions per service. Enable TLS in production and rotate broker credentials.
- Validate event schema/version and tenant context at consumers. Treat message payloads as untrusted input; do not use a payload tenant or actor as authorization proof.
- Ensure idempotency claims are atomic and tenant-scoped. Test duplicate delivery, poison messages, replay, DLQ access and outbox publisher privileges.
- Define Temporal namespace, worker identity, task-queue permissions, TLS and payload minimization before production workflows are enabled.

### Data protection and operations
- Replace local Compose passwords and fixed `APP_SECRET` values with environment-specific secret-manager injection. No production secret may be committed to Git or printed in logs.
- Use separate least-privilege database roles for API, migrations, outbox publishers and reporting.
- Define encryption in transit/at rest, backup encryption, restore tests, retention, deletion and audit access for customer data.
- Redact Authorization/Cookie headers, tokens, personal data and financial payloads from logs, traces, metrics and error responses.
- Restrict Grafana, Prometheus, Loki, Tempo and exporters to operator networks with authentication and least privilege.
- Harden images and containers: pinned base image digests, non-root users, read-only filesystem where possible, dropped Linux capabilities, no privileged containers, resource limits and patched images.

### API contracts and release assurance
- Complete REST `/api/v1` migration without silently breaking current clients; publish and validate versioned OpenAPI contracts.
- Add Pact consumer/provider verification for critical service edges, including 401/403 and tenant-boundary cases.
- Add Buf lint and breaking-change checks for versioned Protobuf contracts.
- Add DAST against a disposable integration environment and an authorization-focused API test suite. Security scans do not replace runtime tests or manual review.
- Require successful tests, dependency audit, CodeQL, Trivy triage and a deployment security review before production release.

## Acceptance criteria for calling the platform production-ready

1. All public traffic enters through TLS-protected gateway routes; internal ports are not published.
2. Every resource service validates its own token issuer, signature, audience, expiry and required scope.
3. Every tenant-owned data access is isolated and has cross-tenant negative integration tests.
4. Every service-to-service call uses a dedicated workload identity or explicitly scoped delegated identity.
5. No production secret or signing key is present in repository history, images, logs or CI output.
6. Rate limiting, broker permissions, health checks and observability access are verified in a production-like environment.
7. Dependency, static-analysis, contract, integration and DAST checks run successfully and findings are triaged.
8. Backup restore, key rotation, credential rotation and incident-response procedures have been exercised.

## Local verification commands

Run these from the repository root after pulling the branch:

```bash
# Audit all Composer lockfiles
find . -type f -name composer.lock -not -path '*/vendor/*' -print

# Per Composer project
composer audit --locked

# Validate Compose configuration
docker compose config --quiet

# Run the repository's documented quality/test pipeline
# (use the root README and each service's README for exact commands)
```

A green CI run on this branch is evidence for the checks that actually ran. It is not a substitute for the production deployment checks above.
