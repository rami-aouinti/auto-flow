# Customer Service — API Reference

**Direct local URL:** http://localhost:8089  
**Authentication:** Keycloak token with audience `autoflow-customer`, tenant claim and endpoint-specific permission. Portal `/me` routes use `customers:portal` and resolve the customer from the JWT subject.

## HTTP endpoints

| Method | Path | Permission / access | Purpose | Arguments and request body | Documented / expected responses |
|---|---|---|---|---|---|
| POST | /api/v1/customers | customers:write | Create individual or company customer. | Idempotency-Key required. Individual: firstName and lastName. Company: legalName. Other optional contact fields are DTO-defined. | 201, 400, 401, 403, 409, 422 |
| GET | /api/v1/customers | customers:read | Paginated customer list. | q?, status?, type?; pagination options as implemented. | 200, 400, 401, 403 |
| GET | /api/v1/customers/{id} | customers:read | Read profile, addresses and consents. | id (path). | 200, 401, 403, 404 |
| PATCH | /api/v1/customers/{id} | customers:write | Partial profile update. | id (path); JSON partial customer fields. | 200, 400, 401, 403, 404, 422 |
| DELETE | /api/v1/customers/{id} | customers:manage | Soft archive customer. | id (path). | 204, 401, 403, 404 |
| GET | /api/v1/customers/{id}/addresses | customers:read | List addresses. | id (path). | 200, 401, 403, 404 |
| POST | /api/v1/customers/{id}/addresses | customers:write | Add address. | id (path); address DTO; countryCode ISO alpha-2. | 201, 400, 401, 403, 404, 422 |
| PUT | /api/v1/customers/{id}/addresses/{addressId} | customers:write | Replace address. | id and addressId (path); complete address DTO. | 200, 400, 401, 403, 404, 422 |
| DELETE | /api/v1/customers/{id}/addresses/{addressId} | customers:write | Soft-delete address. | id and addressId (path). | 204, 401, 403, 404 |
| GET | /api/v1/customers/{id}/consents | customers:read | Read consent decisions. | id (path). | 200, 401, 403, 404 |
| PUT | /api/v1/customers/{id}/consents | customers:write | Record consent decisions. | id (path); consent decision DTO. | 200, 400, 401, 403, 404, 422 |
| GET | /api/v1/customers/{id}/history | customers:read | Read audit history. | id (path). | 200, 401, 403, 404 |
| PUT | /api/v1/customers/{id}/identity | customers:manage | Link Keycloak subject after administrator identity verification. | id (path); identity subject DTO. | 200, 401, 403, 404, 409 |
| GET | /api/v1/customers/me | customers:portal | Read own customer profile mapped from JWT subject. | No arguments. | 200, 401, 403, 404 |
| PATCH | /api/v1/customers/me | customers:portal | Update own profile. | Partial customer fields. | 200, 400, 401, 403, 422 |
| GET | /api/v1/customers/me/addresses | customers:portal | List own addresses. | No arguments. | 200, 401, 403 |
| POST | /api/v1/customers/me/addresses | customers:portal | Add own address. | Address DTO; countryCode ISO alpha-2. | 201, 400, 401, 403, 422 |
| PUT | /api/v1/customers/me/addresses/{addressId} | customers:portal | Update own address. | addressId (path); complete address DTO. | 200, 400, 401, 403, 404, 422 |
| DELETE | /api/v1/customers/me/addresses/{addressId} | customers:portal | Delete own address. | addressId (path). | 204, 401, 403, 404 |
| GET | /api/v1/customers/me/consents | customers:portal | Read own consents. | No arguments. | 200, 401, 403 |
| PUT | /api/v1/customers/me/consents | customers:portal | Update own consent decisions. | Consent decision DTO. | 200, 400, 401, 403, 422 |
| GET | /api/v1/realtime/access | customers:read | Issue five-minute tenant-specific Mercure subscriber cookie. | No arguments. | 200, 401, 403 |

## Health and operations

- GET /health/ready — readiness; no JWT.

## Common response semantics

- `400`: malformed JSON, query string, or required header (when applicable).
- `401`: missing/invalid bearer token on protected routes.
- `403`: authenticated caller lacks the endpoint permission or tenant access.
- `404`: resource is unknown or intentionally hidden by tenant/ownership isolation.
- `409`: business lifecycle conflict or idempotency key reused for a different command.
- `412`: stale `If-Match` version for optimistic concurrency.
- `422`: syntactically valid request that fails domain validation.
- `503`: required upstream service/dependency unavailable.

Only status codes explicitly described in the service README or API contract are asserted in the endpoint table. Other statuses in the common section are general meanings and may not be emitted by every endpoint.

## Testing through Swagger UI

Open [http://localhost:8096](http://localhost:8096). The gateway base URL is `http://localhost:8088`; service routes are forwarded without stripping their API path. Use the raw Keycloak access token in **Authorize** for protected services. For idempotent commands, provide a unique `Idempotency-Key`. For versioned commands, read the current response `ETag` and send it unchanged as `If-Match`.

## Contract accuracy

Request fields are marked as DTO-defined where the service README does not publish the full DTO. The central OpenAPI contract provides strict schemas only for request bodies whose complete fields are confirmed by service documentation. Keep this file and `docs/api/openapi.json` synchronized when changing controllers, validators or response DTOs.
