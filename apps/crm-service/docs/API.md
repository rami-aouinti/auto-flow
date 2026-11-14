# CRM Service — API Reference

**Base URL:** http://localhost:8094 (gateway: http://localhost:8088/api/v1/crm)  
**Authentication:** Keycloak bearer token + tenant claim. Every route has the operation-specific permission below.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/v1/crm/applications` | crm:applications:write + customers:read | Idempotency-Key required. JSON: customerId required; offerId optional; vehicleId, quoteId and optional message. Optional references require corresponding upstream read scopes. | 201, 400, 401, 403, 409, 422, 503 |
| GET | `/api/v1/crm/applications` | crm:applications:read | status?, customerId?, pagination implementation-defined. | 200, 400, 401, 403 |
| GET | `/api/v1/crm/applications/{id}` | crm:applications:read | id path. | 200, 401, 403, 404 |
| POST | `/api/v1/crm/applications/{id}/convert` | crm:applications:manage + orders:write | id path; approved and eligible application only; no body documented. | 201, 401, 403, 404, 409, 422, 503 |
| PATCH | `/api/v1/crm/applications/{id}/status` | crm:applications:manage | id; JSON target status and optional reason. | 200, 400, 401, 403, 404, 409, 422 |
| PUT | `/api/v1/crm/applications/{id}/assignment` | crm:applications:assign | id; JSON advisor identifier. | 200, 400, 401, 403, 404, 422 |
| PUT | `/api/v1/crm/applications/{id}/eligibility` | crm:eligibility:manage | id; JSON eligibility outcome and bounded result. This records a review, not an automated credit decision. | 200, 400, 401, 403, 404, 422 |
| POST | `/api/v1/crm/applications/{id}/tasks` | crm:tasks:write | id; task payload (title; optional description, dueAt, assignee). | 201, 400, 401, 403, 404, 422 |
| GET | `/api/v1/crm/applications/{id}/tasks` | crm:tasks:read | id path. | 200, 401, 403, 404 |
| PATCH | `/api/v1/crm/applications/{id}/tasks/{taskId}` | crm:tasks:write | id + taskId; task patch payload. | 200, 400, 401, 403, 404, 422 |
| POST | `/api/v1/crm/applications/{id}/interactions` | crm:interactions:write | id; JSON channel, subject, body, occurredAt. | 201, 400, 401, 403, 404, 422 |
| GET | `/api/v1/crm/applications/{id}/interactions` | crm:interactions:read | id path. | 200, 401, 403, 404 |
| GET | `/api/v1/crm/applications/{id}/history` | crm:applications:read | id path; audit history. | 200, 401, 403, 404 |

Submission synchronously validates external references with their owning services; no cross-service database joins. Conversion creates an Order and is idempotent by application conversion state.

## Health
- `GET /health/ready` — readiness.
