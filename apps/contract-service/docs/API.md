# Contract Service — API Reference

**Base URL:** http://localhost:8090  
**Authentication:** Keycloak audience `autoflow-contract`, tenant claim and route permission. Create also requires `orders:read` and audience `autoflow-order`. PDF operations additionally require audience `autoflow-document` and `documents:write` (generation) / `documents:read` (download).

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/v1/contracts` | contracts:write + orders:read | Idempotency-Key required. JSON: orderId, contractType (vehicle_purchase, vehicle_lease, vehicle_subscription). | 201, 400, 401, 403, 404, 409, 422 |
| GET | `/api/v1/contracts` | contracts:read | Paginated; optional status filter. | 200, 400, 401, 403 |
| GET | `/api/v1/contracts/{contractId}` | contracts:read | contractId path; legal snapshot. | 200, 401, 403, 404 |
| GET | `/api/v1/contracts/{contractId}/history` | contracts:read | contractId path. | 200, 401, 403, 404 |
| POST | `/api/v1/contracts/{contractId}/prepare` | contracts:write | contractId; Idempotency-Key required; draft → prepared. | 200, 401, 403, 404, 409 |
| POST | `/api/v1/contracts/{contractId}/cancel` | contracts:manage | contractId; Idempotency-Key required; draft/prepared → cancelled. | 200, 401, 403, 404, 409 |
| POST | `/api/v1/contracts/{contractId}/document` | contracts:write + documents:write | Generate immutable PDF draft, upload to Document Service and persist canonical Document UUID/checksum; repeated calls return existing version. | 200, 401, 403, 404, 409, 503 |
| GET | `/api/v1/contracts/{contractId}/document` | contracts:read + documents:read | Proxy canonical PDF bytes from Document Service and verify SHA-256. | 200 (application/pdf), 401, 403, 404, 503 |
| POST | `/api/v1/contracts/{contractId}/send-for-signature` | contracts:manage | contractId; create DocuSign envelope; provider config required. | 202, 401, 403, 404, 409, 502, 503 |

Document Service owns the canonical PDF bytes and encrypted object storage; Contract keeps a private local working copy only for the DocuSign adapter. Run `bin/smoke-test-document-integration.php` with `DOCUMENT_E2E_TOKEN` and a prepared `CONTRACT_E2E_CONTRACT_ID` to verify upload, idempotent generation, download and checksum.

A unique constraint prevents more than one contract for the same tenant/order/type/version. DocuSign is disabled until provider credentials, RSA key and Connect HMAC configuration are supplied.

## Health
- `GET /health/ready` — readiness.
