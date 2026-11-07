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

Individuals require firstName and lastName; companies require legalName. Email is normalized and unique among active customers in a tenant. Address countryCode is ISO alpha-2. Replaying a create key with the same request returns the original resource; a different request with the same key returns 409.

## Security and privacy
- Tenant comes only from the verified JWT principal. Each operation sets transaction-local app.tenant_id; PostgreSQL RLS isolates every customer-owned table.
- Runtime DB role is non-superuser and NOBYPASSRLS.
- PII responses use Cache-Control: no-store.
- Archive is not GDPR erasure. A production deployment still needs a controller-approved erasure/export process, retention policy, legal basis, encryption/key management and appropriate access logging.
- No consent row is not permission.

## Local
Direct API: http://localhost:8089. Gateway: http://localhost:8088/api/v1/customers.
Run: docker compose --env-file .env.example up -d --build customer-service
Health: http://localhost:8089/health/ready

The PostgreSQL bootstrap applies the idempotent migration to fresh and existing volumes without deleting data. Keycloak imports the new audience and roles only when the realm is first created; existing realms require the mapper and roles to be added manually.

## Boundary
Customer does not own credentials, orders, offers, payments, invoices or contracts. Other bounded contexts keep customer identifiers and integrate through this API or a future versioned Customer outbox event. No cross-service database joins are introduced.
