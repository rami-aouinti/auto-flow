# 15 — Security, tenants and API platform

This chapter describes the security architecture and its incremental delivery status. Core authentication, Pricing tenant isolation, ownership and Pricing gRPC authorization are already present in the repository; gateway, distributed rate limiting, contract automation and production deployment hardening remain separate release gates. See the [security hardening plan](../security-hardening-plan.md) for the full implementation truth and acceptance criteria. A documented target is not evidence of a deployed control.

## 1. Security model at a glance

```text
Browser / Mobile Client
        |
        | OIDC Authorization Code + PKCE
        v
     Keycloak  ---- JWKS public keys ----+
        |                                |
        | short-lived RS256 access token |
        v                                v
   Traefik Edge Gateway           Offer / Pricing services
   - TLS and routing              - verify JWT locally
   - coarse rate limits           - validate issuer/audience/time
   - trusted proxy headers        - build principal + tenant context
                                  - enforce service-owned policies
                                           |
                                           v
                                  Tenant-scoped repositories
```

Keycloak is the identity provider. Traefik is the network edge. The Symfony services are resource servers and remain responsible for authorization and data isolation.

## 2. Authentication is not authorization

Authentication answers: **who is calling?**

Authorization answers: **may this principal perform this action on this resource in this tenant?**

A valid JWT only establishes a verified identity and claims. Pricing still owns the decision whether that identity may request a quote; Offer owns its own resource policy. CoreBundle may normalize the verified identity, but it must not contain Offer/Pricing business rules.

## 3. JWT contract

**Implemented first slice:** both Symfony services protect `/api/*`, while `/health` and development-only Swagger endpoints remain public. Offer accepts audience `autoflow-offer`; Pricing accepts `autoflow-pricing`. The CoreBundle verifies RS256/JWKS and attaches an `AuthenticatedPrincipal` to the request. Offer performs standard OAuth 2.0 Token Exchange with the confidential `autoflow-offer-to-pricing` client, using the verified incoming access token as `subject_token` and `autoflow-pricing` as the target audience. Pricing receives a token for its own audience while retaining the end-user `sub`; it stores that subject as quote owner. The CoreBundle resolves authorization scopes from the JWT `scope` claim and Keycloak `realm_access.roles`. The local Keycloak realm configures the requester client audience, maps the user's `tenant_id` attribute into both the incoming and exchanged tokens, and grants the demo user `offers:read` and `pricing:quote` realm roles. The password-grant client is strictly for local smoke testing; browser applications must use Authorization Code + PKCE.

The resource services accept bearer access tokens and validate:
- signature against the issuer's JWKS;
- explicitly allowed algorithm (RS256);
- exact trusted issuer;
- audience for the receiving service;
- expiration and not-before timestamps;
- required subject and tenant context for tenant-protected routes.

A conceptual payload (illustrative only):

```json
{
  "iss": "https://identity.autoflow.local/realms/autoflow",
  "sub": "user-123",
  "aud": ["autoflow-offer"],
  "exp": 1790000000,
  "iat": 1789999700,
  "tenant_id": "tenant-acme",
  "scope": "offers:read"
}
```

The payload is not trusted until the signature and claims have been validated. Never accept an unsigned token, a token with an unexpected algorithm, a token for another audience, or a tenant ID supplied only in a request header.

## 4. Tenant isolation

Tenant context is resolved from the verified `tenant_id` claim for user tokens. Offer forwards that tenant to Pricing using `X-Tenant-ID` alongside its own service token. Pricing accepts this delegation only when the verified service token has the dedicated `tenant:delegate` scope. A tenant already present in a token cannot be overwritten by a different header value. Tenant-required HTTP routes are configured explicitly and fail closed when no valid tenant context can be resolved. Pricing persists quote snapshots with mandatory `tenant_id` and `created_by` values. The tenant is resolved from the verified principal; the creator is the verified JWT `sub`, never a body or header value. PostgreSQL row-level security is enabled and forced on `pricing_quotes`; the policy uses the transaction-local `app.tenant_id` setting for both row visibility and write checks. The Pricing service connects with a dedicated `NOSUPERUSER NOBYPASSRLS` role rather than the database bootstrap administrator. The repository sets the tenant inside the same transaction as each operation. Pricing also filters quote reads, lists and soft deletes by `created_by` inside the tenant. Tenant-wide read access requires `pricing:quote:read:any`; tenant-wide deletion requires `pricing:quote:delete:any`. Without those explicit scopes, a principal can only access quotes it created. Resource authorization remains service-owned.

The tenant identifier in the access token is the active tenant, not proof that the user belongs to every tenant. Tenant selection must be validated against membership before a token for that tenant is issued.

## 5. Permissions

Use coarse scopes/client roles in tokens and fine-grained policies inside the service. The CoreBundle provides the `RequiresScope` method attribute and `ScopeAuthorizationSubscriber`; the Offer and Pricing REST actions require `offers:read` and `pricing:quote` respectively. Pricing additionally recognizes:
- `pricing:quote:read:any` — read any quote inside the active tenant;
- `pricing:quote:delete:any` — soft-delete any quote inside the active tenant.

Without these elevated scopes, quote read/list/delete operations are restricted to the verified JWT subject that created the quote. A quote owned by another user is returned as 404 to avoid resource enumeration. A service must still check resource ownership and tenant context. Use deny-by-default and return 401 for invalid/missing authentication and 403 for an authenticated but forbidden request.

## 6. Service-to-service authentication

Offer calling Pricing uses OAuth2 Client Credentials with a dedicated workload identity and a Pricing audience. The client requests `pricing:quote tenant:delegate`; grant `tenant:delegate` only to the Offer service account in Keycloak. Pricing validates the token and scope before accepting the delegated tenant header. Do not share one generic secret across services, and do not blindly forward an end-user token.

## 6.1 gRPC authentication

Pricing's gRPC `CalculateQuote` method uses `GrpcRequestAuthenticator`. Callers must send bearer access token metadata and, for a delegated service tenant, `x-tenant-id` metadata. The method validates the JWT, requires `pricing:quote`, resolves tenant delegation through `tenant:delegate`, and maps failures to standard gRPC statuses. gRPC metadata is not trusted merely because it is transported over an internal network.

## 7. Gateway and rate limits

**Implemented in Docker Compose:** Traefik v3 is the single HTTP entry point on `http://localhost:8088`. Its file-provider configuration routes `/api/catalog/**`, `/api/offers/**`, `/api/pricing/**`, `/api/v1/orders/**` and `/api/payments/**` to their owning Symfony services without rewriting their paths. Each upstream has an active `/health/ready` health check. The gateway applies CORS for the local Nuxt origins, security response headers, an in-memory per-IP rate limit (120 requests/minute with a burst of 60), and an in-flight request ceiling (100). JSON access logs deliberately drop request headers so bearer tokens are not written to logs. Prometheus scrapes gateway metrics on the internal `:8081` entrypoint; the dashboard and internal metrics/health entrypoint are not published on the host. The Docker socket is not mounted.

Direct backend host ports are retained temporarily for local diagnostics and Swagger UI. Production should expose only the gateway and internalize backend ports. The current local gateway does not terminate TLS and its rate-limit counters are local to one replica. Redis-backed per-tenant quotas, trusted proxy configuration, TLS/certificate automation and production ingress policy remain release gates.

The gateway is not a substitute for service-level JWT validation, authorization or tenant isolation. Every Symfony backend continues to validate Keycloak issuer, signature, audience and service-owned scopes.

## 8. API versioning and contracts

REST public paths use `/api/v1`. Each service owns its OpenAPI document. Breaking changes require a new version and a deprecation period.

gRPC uses versioned Protobuf packages such as `pricing.v1`. Buf checks schema compatibility and field-number safety.

Pact verifies consumer/provider expectations for important REST interactions. OpenAPI validation and runtime provider verification complement Pact; they do not replace it.

## 9. Delivery status

| Capability | Status |
| --- | --- |
| Keycloak local identity provider | Implemented in JWT foundation branch (local realm) |
| RS256/JWKS validation in CoreBundle | Implemented in JWT foundation branch; CI pending |
| Principal and tenant request context | Implemented: verified HTTP principal, request-scoped TenantContext, and explicit tenant delegation |
| REST scope enforcement | Implemented for Offer and Pricing; Pricing enforces per-user quote ownership and explicit tenant-wide read/delete scopes |
| Tenant delegation policy and negative tests | Implemented for HTTP and gRPC; Pricing enforces PostgreSQL tenant RLS and per-user quote ownership with integration tests |
| Traefik edge gateway | Implemented in Compose: path routing, backend health checks, CORS, security headers, in-flight limit, JSON access logs and Prometheus metrics |
| Per-IP edge rate limiting | Implemented in Traefik (in-memory per replica) |
| Redis-backed per-tenant rate limiting | Planned |
| REST `/api/v1` migration | Planned |
| Pact REST contracts | Planned |
| Buf Protobuf compatibility checks | Planned |
| Order Management tenant RLS and immutable snapshots | Implemented in master; preserve negative tests |
| Order upstream token delegation | Review required; current validator forwards the inbound bearer token |
| Composer audit / Trivy / Dependency Review / CodeQL | Added on security-hardening branch; first CI runs pending |
| Tenant membership validation at token issuance | Planned |
| Production TLS, secret manager and internal workload mTLS | Deployment hardening required |

The current security increment adds RS256/JWKS verification, audience isolation, REST scope checks, HTTP tenant delegation guarded by `tenant:delegate`, and JWT/scope/tenant checks on Pricing's gRPC quote method. Vehicle Catalog adds its own `autoflow-catalog` audience with `catalog:read` and `catalog:manage` scopes; its global reference dataset is not tenant-owned in Sprint 1. Pricing quote writes, reads, pagination, and deletion are tenant-bound and protected by PostgreSQL row-level security. The next increments are tenant membership validation at identity issuance, explicit ownership propagation for delegated user workflows, update semantics for immutable quote snapshots, and equivalent isolation for other services. See ADR-016 for decisions and trade-offs.


## 10. Pricing quote persistence and PostgreSQL RLS

Pricing stores each calculated quote as an immutable snapshot in `pricing_quotes`. Deletion is a soft-delete operation (`deleted_at`) so the financial calculation snapshot remains available for audit and retention policies. The application layer depends on `PricingQuoteRepositoryInterface`; the PostgreSQL adapter is an infrastructure implementation. `quote_id` is generated independently of the tenant; `tenant_id` and `created_by` are always taken from the verified HTTP/gRPC principal, never from the request body.

PostgreSQL enforces tenant isolation with row-level security:
- `ENABLE ROW LEVEL SECURITY` and `FORCE ROW LEVEL SECURITY` are applied to `pricing_quotes`;
- `USING` restricts visible rows to `current_setting('app.tenant_id', true)`;
- `WITH CHECK` rejects writes for a different tenant;
- the repository sets the tenant with `set_config(..., true)` inside the same transaction as the insert;
- the Pricing runtime uses the dedicated `autoflow_pricing` role with `NOSUPERUSER` and `NOBYPASSRLS`. Never run the service as the PostgreSQL bootstrap/superuser account.

For a fresh local database, Docker Compose initializes the restricted role and quote schema automatically. If the existing PostgreSQL volume was created before this migration, apply it once:

```bash
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/pricing-service/migrations/00_create_pricing_database_role.sql
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/pricing-service/migrations/Version20261104000100CreatePricingQuotes.sql
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/pricing-service/migrations/Version20261104000200AddPricingQuoteSoftDelete.sql
docker compose exec -T postgres psql -U autoflow -d autoflow < apps/pricing-service/migrations/Version20261104000300AddPricingQuoteOwner.sql
docker compose up -d --force-recreate pricing-service
```

The role password in the local initialization SQL is for development only. Production deployments must provision a dedicated non-superuser database role and secret through the deployment secret manager, then grant only the required schema/table privileges. Do not reuse the local password.

The Pricing Service GitHub workflow starts a real PostgreSQL instance and verifies tenant isolation plus same-tenant ownership: user B cannot read, list or delete user A's quote. Elevated read/delete scopes are translated to tenant-wide repository filters only after the HTTP controller has checked the verified principal. Unit tests remain runnable without PostgreSQL; the integration tests are enabled explicitly in CI.


### Tenant-scoped quote API

All routes below require the `pricing:quote` scope and an authenticated tenant context. The tenant identifier is resolved from the verified principal, not accepted from query parameters or request bodies.

| Method | Path | Behavior |
| --- | --- | --- |
| `POST` | `/api/pricing/quotes` | Calculate and persist a quote; returns `quoteId` |
| `GET` | `/api/pricing/quotes/{quoteId}` | Read own quote; another user or tenant receives the same 404 as a missing quote. `pricing:quote:read:any` grants tenant-wide read access |
| `GET` | `/api/pricing/quotes?limit=50&offset=0` | List only the caller's quotes; `pricing:quote:read:any` grants tenant-wide listing; limit is 1-100 |
| `DELETE` | `/api/pricing/quotes/{quoteId}` | Soft-delete own quote; `pricing:quote:delete:any` grants tenant-wide deletion. Other users receive 404 |

Every repository operation opens its own transaction, sets the transaction-local `app.tenant_id`, and relies on PostgreSQL RLS for row visibility and write enforcement. The adapter also validates tenant identifiers before opening a transaction. A missing tenant setting exposes no rows because the RLS policy compares against the absent/empty setting.


### Quote ownership and privileged access

The `created_by` column stores the JWT subject (`sub`) of the verified caller for both REST and gRPC quote creation. The Pricing application never accepts a creator ID from user-controlled input. Existing rows are migrated to the non-user owner `legacy:unassigned` and are therefore not visible to ordinary users; an explicitly provisioned tenant administrator can review them with the tenant-wide read scope. Grant `pricing:quote:read:any` and `pricing:quote:delete:any` only to trusted tenant-administrator roles in Keycloak. These scopes do not bypass PostgreSQL RLS or tenant context.


### Delegated identity: Offer to Pricing

The downstream call must not replace the end-user identity with a generic service-account subject. Offer first authenticates the original bearer token through the CoreBundle. It then exchanges that exact token at Keycloak's standard Token Exchange V2 endpoint using the confidential workload client. The request includes the RFC 8693 token-exchange grant, the incoming access token as `subject_token`, and the Pricing resource audience. Offer sends only the returned access token to Pricing. There is no fallback to client-credentials tokens: if exchange fails, the downstream operation fails closed.

Keycloak configuration requirements:

- Enable **Standard Token Exchange** for the confidential `autoflow-offer-to-pricing` requester client.
- Ensure the original user token includes `autoflow-offer-to-pricing` in its `aud` claim. The local realm adds this audience mapper to `autoflow-local-client`.
- Map a trusted user tenant attribute to the `tenant_id` claim on both the initial and exchanged token. The local demo user is assigned `tenant-demo`.
- Allow the requester client to exchange to the `autoflow-pricing` audience.
- Assign the required permissions to users through realm roles (for example, `offers:read` and `pricing:quote`). The CoreBundle maps these realm roles into its authorization scope list.
- Keep the client secret in a secret manager/environment injection; never commit production credentials.

The implementation uses Keycloak's supported Standard Token Exchange V2, not the deprecated legacy exchange. The exchanged token is validated independently by Pricing against its issuer, signature and audience. The tenant still comes from the verified tenant claim; the `X-Tenant-ID` header cannot override it. Quote ownership comes from the exchanged token's verified `sub`, never from a user-controlled request field.
