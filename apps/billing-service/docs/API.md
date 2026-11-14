# Billing Service — API Reference

**Direct local URL:** http://localhost:8087  
**Authentication:** Keycloak tenant claim and `billing:read` scope.

## HTTP endpoints

| Method | Path | Permission / access | Purpose | Arguments and request body | Documented / expected responses |
|---|---|---|---|---|---|
| GET | /api/v1/billing/invoices | billing:read | List invoices. | No query parameters documented. | 200, 401, 403 |
| GET | /api/v1/billing/invoices/{id} | billing:read | Read invoice. | id (path). | 200, 401, 403, 404 |
| GET | /api/v1/billing/invoices/{id}/document | billing:read | Download PDF for legally complete issued invoice; draft invoice returns conflict. | id (path). | 200 (application/pdf), 401, 403, 404, 409 |
| GET | /api/v1/billing/credit-notes | billing:read | List credit notes. | No query parameters documented. | 200, 401, 403 |
| GET | /api/v1/billing/credit-notes/{id} | billing:read | Read credit note. | id (path). | 200, 401, 403, 404 |
| GET | /api/v1/billing/summary | billing:read | Read billing summary. | No query parameters documented. | 200, 401, 403 |

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
