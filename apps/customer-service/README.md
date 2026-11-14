# AutoFlow Customer Service

Tenant-scoped Customer bounded context. Customer Service owns customer profiles, contact details, addresses and consent decisions. Keycloak remains the identity provider; passwords are never stored here.

## Responsibilities
- Individual and company profiles, legal name and VAT identifier.
- Email, phone and preferred locale.
- Multiple billing, shipping, residential and business addresses.
- Purpose-based consent decisions with source and timestamp.
- Soft archival, optimistic versioning and actor audit history.
- Idempotent customer creation and PostgreSQL row-level security.
- Problem Details errors and no-store responses for personal data.

## API
Every route requires a Keycloak token with audience autoflow-customer, tenant claim and the required role.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| POST | /api/v1/customers | customers:write | Create individual/company; Idempotency-Key required |
| GET | /api/v1/customers | customers:read | Paginated list; q, status, type filters |
| GET | /api/v1/customers/{id} | customers:read | Profile, addresses and consents |
| PATCH | /api/v1/customers/{id} | customers:write | Partial profile update |
| DELETE | /api/v1/customers/{id} | customers:manage | Soft archive |
| GET/POST | /api/v1/customers/{id}/addresses | read/write | List and add addresses |
| PUT/DELETE | /api/v1/customers/{id}/addresses/{addressId} | customers:write | Replace or soft-delete address |
| GET/PUT | /api/v1/customers/{id}/consents | read/write | Read or record consent decision |
| GET | /api/v1/customers/{id}/history | customers:read | Audit history |
| PUT | /api/v1/customers/{id}/identity | customers:manage | Link a Keycloak subject after administrator identity verification (audited, unique per tenant) |
| GET/PATCH | /api/v1/customers/me | customers:portal | Read/update only the profile mapped to the JWT subject |
| GET | /api/v1/customers/me/addresses | customers:portal | Read own addresses |
| GET/PUT | /api/v1/customers/me/consents | customers:portal | Read/update own consent decisions |
| GET | /api/v1/realtime/access | customers:read | Issue a five-minute tenant-specific Mercure subscriber cookie |

Individuals require firstName and lastName; companies require legalName. Email is normalized and unique among active customers in a tenant. Address countryCode is ISO alpha-2. Replaying a create key with the same request returns the original resource; a different request with the same key returns 409.

## Security and privacy
- Tenant and self-service Keycloak subject come only from the verified JWT principal. Self-service queries match both tenant_id and keycloak_subject; clients cannot submit a customer ID to select another profile.
- Each operation sets transaction-local app.tenant_id; PostgreSQL RLS isolates every customer-owned table.
- Keycloak subject linking requires customers:manage, is unique per tenant and creates an audit event.
- Mercure subscriber JWTs are signed server-side, short-lived, private-topic scoped and stored in an HttpOnly cookie; publisher keys never reach the browser.
- Runtime DB role is non-superuser and NOBYPASSRLS.
- PII responses use Cache-Control: no-store.
- Archive is not GDPR erasure. A production deployment still needs a controller-approved erasure/export process, retention policy, legal basis, encryption/key management and appropriate access logging.
- No consent row is not permission.

## Local
Direct API: http://localhost:8089. Gateway: http://localhost:8088/api/v1/customers.
Run: docker compose --env-file .env.example up -d --build customer-service
Health: http://localhost:8089/health/ready

The PostgreSQL bootstrap applies the idempotent schema and identity migrations to fresh and existing volumes without deleting data. It also seeds a local-only customer account. The realm export provisions `customer-demo` / `customer-demo` with `customers:portal` and the matching fixed subject; use only for local development. If the `autoflow` realm already exists, Keycloak does not merge the import automatically: create the `customers:portal` realm role, create/enable a customer user with the `tenant_id=tenant-demo` user attribute, assign the role, then copy that user's `sub` and link it to the seeded Demo Customer from Customer 360. Do not delete an existing realm just to apply this demo user.

## Customer self-service and realtime

The local portal is available at `/portal`. Sign in as `customer-demo` / `customer-demo` after importing the realm. Its Keycloak `sub` is mapped to the local seeded customer record. In production, the tenant administrator must verify account ownership before linking the exact subject. In production, create/link identities only through the administrator identity-link endpoint after independently verifying the account owner; never accept a customer ID or subject from the self-service form.

Customer Service issues a five-minute HttpOnly `mercureAuthorization` cookie scoped to the caller's tenant topic. Only staff with `customers:read` receive the subscriber cookie; customer-portal accounts cannot subscribe to internal tenant activity. The RabbitMQ → Mercure bridge publishes only a small event summary (no raw payload or PII) to private tenant topics. Events without a valid tenant identifier are dead-lettered rather than broadcast. For production, set a strong shared `MERCURE_SUBSCRIBER_JWT_KEY`, `MERCURE_PUBLISHER_JWT_KEY`, the public `MERCURE_CORS_ORIGINS`, `AUTOFLOW_MERCURE_TOPIC_BASE`, and—when the hub is on a sibling subdomain—`MERCURE_COOKIE_DOMAIN` (for example `.example.com`). Set `MERCURE_COOKIE_SECURE=true`; use `MERCURE_COOKIE_SAMESITE=None` only if the browser and hub are genuinely cross-site, and then require HTTPS.

## Downstream order integration

Order Service resolves `GET /api/v1/customers/{customerId}` with the authenticated caller token and its own `customers:read` permission. The customer profile response includes the profile version, status, archival timestamp and active addresses. Order stores a minimal immutable snapshot in its own database; Customer does not expose its database or create cross-service foreign keys. Consent records are not copied into orders. The synchronous Order integration is implemented; Customer Outbox publication remains future work.

## Boundary
Customer does not own credentials, orders, offers, payments, invoices or contracts. Other bounded contexts keep customer identifiers and integrate through this API or a future versioned Customer outbox event. No cross-service database joins are introduced.
