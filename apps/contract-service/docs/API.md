# Contract Service — API Reference

**Base URL:** http://localhost:8090  
**Authentication:** Keycloak audience `autoflow-contract`, tenant claim and route permission. Create also requires `orders:read` and audience `autoflow-order`.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/v1/contracts` | contracts:write + orders:read | Idempotency-Key required. JSON: orderId, contractType (vehicle_purchase, vehicle_lease, vehicle_subscription). | 201, 400, 401, 403, 404, 409, 422 |
| GET | `/api/v1/contracts` | contracts:read | Paginated; optional status filter. | 200, 400, 401, 403 |
| GET | `/api/v1/contracts/{contractId}` | contracts:read | contractId path; legal snapshot. | 200, 401, 403, 404 |
| GET | `/api/v1/contracts/{contractId}/history` | contracts:read | contractId path. | 200, 401, 403, 404 |
| POST | `/api/v1/contracts/{contractId}/prepare` | contracts:write | contractId; Idempotency-Key required; draft → prepared. | 200, 401, 403, 404, 409 |
| POST | `/api/v1/contracts/{contractId}/cancel` | contracts:manage | contractId; Idempotency-Key required; draft/prepared → cancelled. | 200, 401, 403, 404, 409 |
| POST | `/api/v1/contracts/{contractId}/document` | contracts:write | contractId; generate immutable PDF draft; repeated calls return existing version. | 201, 401, 403, 404, 409 |
| GET | `/api/v1/contracts/{contractId}/document` | contracts:read | contractId; PDF download after SHA-256 verification. | 200 (application/pdf), 401, 403, 404 |
| POST | `/api/v1/contracts/{contractId}/send-for-signature` | contracts:manage | contractId; create DocuSign envelope; provider config required. | 202, 401, 403, 404, 409, 502, 503 |

A unique constraint prevents more than one contract for the same tenant/order/type/version. DocuSign is disabled until provider credentials, RSA key and Connect HMAC configuration are supplied.

## Health
- `GET /health/ready` — readiness.
