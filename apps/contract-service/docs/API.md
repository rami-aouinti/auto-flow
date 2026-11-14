# Contract Service — API Reference

**Direct local URL:** http://localhost:8090  
**Authentication:** Keycloak token with audience `autoflow-contract`, tenant claim and endpoint permission. Contract creation also requires `orders:read` and audience `autoflow-order`.

## HTTP endpoints

| Method | Path | Permission / access | Purpose | Arguments and request body | Documented / expected responses |
|---|---|---|---|---|---|
| POST | /api/v1/contracts | contracts:write + orders:read | Create draft contract from an accepted order. | Idempotency-Key required. JSON: orderId, contractType (vehicle_purchase\|vehicle_lease\|vehicle_subscription). | 201, 400, 401, 403, 404, 409, 422 |
| GET | /api/v1/contracts | contracts:read | Paginated contract list. | status? filter. | 200, 400, 401, 403 |
| GET | /api/v1/contracts/{contractId} | contracts:read | Read contract and immutable legal snapshot. | contractId (path). | 200, 401, 403, 404 |
| GET | /api/v1/contracts/{contractId}/history | contracts:read | Read audit history. | contractId (path). | 200, 401, 403, 404 |
| POST | /api/v1/contracts/{contractId}/prepare | contracts:write | Transition draft to prepared. | contractId; Idempotency-Key required; no body documented. | 200, 401, 403, 404, 409 |
| POST | /api/v1/contracts/{contractId}/cancel | contracts:manage | Cancel draft or prepared contract. | contractId; Idempotency-Key required; no body documented. | 200, 401, 403, 404, 409 |
| POST | /api/v1/contracts/{contractId}/document | contracts:write | Generate immutable PDF draft; repeated calls return existing version. | contractId; no body documented. | 201, 401, 403, 404, 409, 503 |
| GET | /api/v1/contracts/{contractId}/document | contracts:read | Download PDF after SHA-256 verification. | contractId (path). | 200 (application/pdf), 401, 403, 404 |
| POST | /api/v1/contracts/{contractId}/send-for-signature | contracts:manage | Create DocuSign envelope; provider configuration required. | contractId; provider configuration is external; no generic body documented. | 202, 401, 403, 404, 409, 502, 503 |

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
