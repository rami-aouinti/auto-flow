# ADR-016 — Shared identity, tenant context and API edge architecture

- **Status:** Accepted as target architecture; implementation is incremental.
- **Date:** 2026-11-04
- **Owners:** AutoFlow platform
- **Related:** ADR-014 (CoreBundle), ADR-015 (RoadRunner)

## Context

AutoFlow has independently bootable Symfony services (currently Offer and Pricing), a technical CoreBundle, and a direct Offer → Pricing HTTP call. The platform needs a consistent security model, tenant isolation, controlled public ingress, traffic protection, API evolution and consumer/provider compatibility.

These concerns are related but must not collapse into one shared business module. Authentication is a platform capability; authorization decisions and tenant-scoped data access remain owned by each bounded context.

## Decisions

### 1. Identity provider and access tokens

- Use **Keycloak (OpenID Connect / OAuth 2.0)** as the self-hostable identity provider for local development and the initial target deployment.
- Use short-lived **JWT access tokens signed with RS256**. Services validate signatures using the issuer's published JWKS; no service shares or stores the signing private key.
- Validate at minimum signature, allowed algorithm, issuer, audience, expiry and not-before. Reject missing or invalid claims; never trust an unverified JWT payload.
- Browser/mobile clients use Authorization Code + PKCE. Service-to-service calls use Client Credentials with distinct service identities and narrowly scoped audiences/permissions. Do not forward a user's bearer token blindly as a substitute for service identity.
- Refresh tokens are for clients and are never sent to resource services.
- Services validate tokens themselves, even when requests arrive through the gateway. The gateway is not the sole security boundary.

### 2. CoreBundle security boundary

CoreBundle may provide technical primitives: bearer-token extraction, JWKS retrieval/cache, cryptographic and standard-claim validation, a normalized authenticated-principal representation, and request-scoped tenant context.

CoreBundle must not own:
- customer, organization, membership or tenant entities;
- role/permission business catalogues;
- resource ownership rules or row-level authorization;
- service-specific authorization decisions.

Each service maps the verified principal to its own authorization policies. A valid token does not automatically authorize every operation.

### 3. Multi-tenancy

- The canonical active tenant identifier is the signed **tenant_id** claim, issued only after the identity/tenant-membership flow has established that the principal may act for that tenant.
- A client-supplied X-Tenant-ID header is not an authority. If accepted as a tenant selector in a future flow, it must be checked against membership and the resulting active tenant must be represented in a newly issued/validated security context.
- Every tenant-owned query, command, cache key, asynchronous message and audit record must carry the tenant context where relevant.
- Data access must be tenant-scoped by construction. A missing tenant on a tenant-protected operation fails closed.
- Cross-tenant administration is an explicit privileged capability, separately audited and never inferred from a generic admin role.
- Tenant membership and tenant lifecycle belong to a future Identity/Organization capability, not CoreBundle.

### 4. Permissions

- Tokens carry stable service/client roles or OAuth scopes, not an unbounded copy of the entire business permission database.
- Each service owns fine-grained policies (for example, `offers:read`, `pricing:quote`) and checks resource-level constraints after authentication.
- Use deny-by-default. Distinguish 401 (missing/invalid authentication) from 403 (authenticated but forbidden).
- Do not use a role name as a substitute for tenant/resource ownership checks.

### 5. API Gateway

- Use **Traefik** as the first edge gateway in Docker Compose and the initial deployment topology: host-based/path-based routing, TLS termination, forwarding headers and edge rate-limit middleware.
- Only the gateway is exposed as the public API ingress in the target topology. Service ports remain private to the Docker/network boundary; direct host ports are a local developer convenience only.
- Keep service-side JWT validation and authorization. The gateway may perform coarse routing and early rejection but cannot replace service authorization.
- Preserve W3C trace context and the correlation ID across the edge and downstream calls. Trust forwarded headers only from known proxy hops.

### 6. Rate limiting

- Apply coarse IP/client limits at the edge and authenticated subject/tenant limits at the service or a shared distributed limiter.
- Use Redis for distributed counters when multiple gateway/service replicas must share limits. In-memory limits are only acceptable for local/single-process tests.
- Define route-specific policies, response headers and HTTP 429 behavior. Use Retry-After when the retry window is known.
- Health checks, internal traffic and public business endpoints have distinct policies. Do not let an attacker bypass limits simply by changing a caller-controlled tenant header.

### 7. API versioning

- Version public REST APIs in the path: `/api/v1/...`.
- Keep OpenAPI documents service-owned and generated per version. A breaking contract change creates a new major API version; additive optional fields are preferred for compatible evolution.
- Deprecate old versions with explicit documentation and lifecycle dates; do not silently change field meaning or remove fields.
- gRPC uses Protobuf package/version namespaces (for example, `pricing.v1`) and additive field evolution. Never reuse deleted field numbers; use Buf breaking checks.
- Internal service APIs are still contracts; “internal” does not mean unversioned or free to break.

### 8. Contract testing

- REST: consumer-driven Pact contracts for critical service interactions, plus OpenAPI validation and backward-compatibility checks in CI.
- gRPC: Protobuf linting and Buf breaking-change checks against the main branch.
- Run provider verification against the actual service implementation. A generated OpenAPI document alone is documentation, not proof of runtime compatibility.
- Contract tests must cover success, validation errors, authentication failures and relevant tenant boundaries.

## Implementation sequence

1. **Security foundation:** Keycloak local realm/configuration, CoreBundle JWT verification and principal context, protected Offer/Pricing routes, service-to-service credential strategy and tests.
2. **Tenant isolation and permissions:** active tenant context, tenant-aware authorization interfaces, example policies and cross-tenant negative tests.
3. **Edge gateway:** Traefik routing, private service network, trusted proxy configuration and edge smoke tests.
4. **Rate limiting:** route policies, Redis-backed distributed limits and 429 tests.
5. **API lifecycle and contracts:** move public routes to `/api/v1`, publish versioned OpenAPI/Protobuf contracts, add Pact and Buf CI checks.

Each phase is delivered as a reviewable pull request. Do not merge a phase until its tests and security boundaries are demonstrated.

## Consequences and trade-offs

- Keycloak avoids inventing password storage, login flows, token issuance and key rotation in the business services, at the cost of operating an identity provider.
- Local JWT validation keeps resource services available during normal identity-provider outages until tokens expire, but JWKS rotation/cache behavior must be correct.
- Tenant isolation requires disciplined data-access patterns and negative tests; a claim alone does not provide isolation.
- Traefik provides a lightweight edge layer, while fine-grained security remains in the services. This deliberately avoids turning the gateway into a business-policy engine.
- Pact and Buf add CI work, but make independently evolving services safer.
- The current Offer → Pricing demonstration must be migrated to an explicit service identity or another deliberate trust model before production use.
