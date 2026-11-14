# Customer Service — API Reference

**Base URL:** http://localhost:8089 (gateway: http://localhost:8088/api/v1/customers)  
**Authentication:** Keycloak audience `autoflow-customer`, tenant claim and per-route permission. Portal routes use `customers:portal` and resolve ownership from the JWT subject.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/v1/customers` | customers:write | Idempotency-Key required. Individual requires firstName+lastName; company requires legalName. | 201, 400, 401, 403, 409, 422 |
| GET | `/api/v1/customers` | customers:read | q?, status?, type?; paginated. | 200, 400, 401, 403 |
| GET | `/api/v1/customers/{id}` | customers:read | id path; returns profile, addresses and consents. | 200, 401, 403, 404 |
| PATCH | `/api/v1/customers/{id}` | customers:write | id path; partial customer fields. | 200, 400, 401, 403, 404, 422 |
| DELETE | `/api/v1/customers/{id}` | customers:manage | id path; soft archive. | 204, 401, 403, 404 |
| GET | `/api/v1/customers/{id}/addresses` | customers:read | id path. | 200, 401, 403, 404 |
| POST | `/api/v1/customers/{id}/addresses` | customers:write | id path; address DTO, countryCode is ISO alpha-2. | 201, 400, 401, 403, 404, 422 |
| PUT | `/api/v1/customers/{id}/addresses/{addressId}` | customers:write | id and addressId path; complete address DTO. | 200, 400, 401, 403, 404, 422 |
| DELETE | `/api/v1/customers/{id}/addresses/{addressId}` | customers:write | id and addressId path; soft delete. | 204, 401, 403, 404 |
| GET | `/api/v1/customers/{id}/consents` | customers:read | id path. | 200, 401, 403, 404 |
| PUT | `/api/v1/customers/{id}/consents` | customers:write | id path; consent decision DTO. | 200, 400, 401, 403, 404, 422 |
| GET | `/api/v1/customers/{id}/history` | customers:read | id path; audit history. | 200, 401, 403, 404 |
| PUT | `/api/v1/customers/{id}/identity` | customers:manage | id path; link verified Keycloak subject; audited and unique per tenant. | 200, 401, 403, 404, 409 |
| GET | `/api/v1/customers/me` | customers:portal | JWT subject mapping; no path argument. | 200, 401, 403, 404 |
| PATCH | `/api/v1/customers/me` | customers:portal | Partial own-profile fields. | 200, 400, 401, 403, 422 |
| GET | `/api/v1/customers/me/addresses` | customers:portal | List own addresses. | 200, 401, 403 |
| POST | `/api/v1/customers/me/addresses` | customers:portal | Add own address DTO. | 201, 400, 401, 403, 422 |
| PUT | `/api/v1/customers/me/addresses/{addressId}` | customers:portal | addressId path; complete address DTO. | 200, 400, 401, 403, 404, 422 |
| DELETE | `/api/v1/customers/me/addresses/{addressId}` | customers:portal | addressId path. | 204, 401, 403, 404 |
| GET | `/api/v1/customers/me/consents` | customers:portal | Read own consent decisions. | 200, 401, 403 |
| PUT | `/api/v1/customers/me/consents` | customers:portal | Update own consent decisions. | 200, 400, 401, 403, 422 |
| GET | `/api/v1/realtime/access` | customers:read | Issue five-minute tenant-specific Mercure subscriber cookie. | 200, 401, 403 |

Create idempotency: same key and same payload returns original resource; same key with different payload returns 409. Active-customer email is normalized and unique per tenant.

## Health
- `GET /health/ready` — readiness.
