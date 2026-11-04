# 15 — Security, tenants and API platform

This chapter records the target architecture from [ADR-016](../adr/ADR-016-security-and-api-platform.md). It is a roadmap, not a claim that the controls are already implemented.

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

Tenant context must be propagated through HTTP, messages, cache keys and audit events when the operation is tenant-scoped. A missing tenant fails closed. Every tenant-owned repository query includes the tenant constraint, and tests must prove that Tenant A cannot read or mutate Tenant B's resources.

The tenant identifier in the access token is the active tenant, not proof that the user belongs to every tenant. Tenant selection must be validated against membership before a token for that tenant is issued.

## 5. Permissions

Use coarse scopes/client roles in tokens and fine-grained policies inside the service. Example:
- `offers:read`
- `pricing:quote`
- `contracts:write`

A service must still check resource ownership and tenant context. Use deny-by-default and return 401 for invalid/missing authentication and 403 for an authenticated but forbidden request.

## 6. Service-to-service authentication

Offer calling Pricing must use an explicit workload identity and a token whose audience is Pricing. Use OAuth2 Client Credentials with a dedicated client identity and least-privilege scope. Do not share one generic secret across every service, and do not blindly forward an end-user token.

## 7. Gateway and rate limits

Traefik is the edge router. The target deployment exposes the gateway, not every service port. Edge rate limits protect against bursts by IP/client; authenticated service/tenant limits protect expensive business operations. Redis provides shared counters across replicas. A rejected request returns 429 and a useful Retry-After header where possible.

The gateway is not a substitute for service-level authentication or authorization.

## 8. API versioning and contracts

REST public paths use `/api/v1`. Each service owns its OpenAPI document. Breaking changes require a new version and a deprecation period.

gRPC uses versioned Protobuf packages such as `pricing.v1`. Buf checks schema compatibility and field-number safety.

Pact verifies consumer/provider expectations for important REST interactions. OpenAPI validation and runtime provider verification complement Pact; they do not replace it.

## 9. Delivery status

| Capability | Status |
| --- | --- |
| Keycloak local identity provider | Planned |
| RS256/JWKS validation in CoreBundle | Planned |
| Principal and tenant request context | Planned |
| Service-specific permission policies | Planned |
| Tenant-scoped persistence and negative tests | Planned |
| Traefik edge gateway | Planned |
| Redis-backed rate limiting | Planned |
| REST `/api/v1` migration | Planned |
| Pact REST contracts | Planned |
| Buf Protobuf compatibility checks | Planned |

Implementation is split into small pull requests, starting with JWT authentication and the tenant/principal context. See ADR-016 for decisions and trade-offs.
