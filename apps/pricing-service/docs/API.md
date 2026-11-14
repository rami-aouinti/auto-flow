# Pricing Service — API Reference

**Base URL:** http://localhost:8082  
**Authentication:** Keycloak JWT, audience `autoflow-pricing`, tenant context and `pricing:quote`. Reads/deletes are caller-owned by default. Optional `pricing:quote:read:any` and `pricing:quote:delete:any` widen access only within the authorized tenant.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/pricing/quotes` | pricing:quote | JSON required: vehicleId, vehiclePrice, downPayment, residualValue, currency, durationMonths, annualInterestRateBasisPoints. Money values are decimal strings with two fractional digits. | 200, 400, 401, 403, 422 |
| GET | `/api/pricing/quotes` | pricing:quote | limit?, offset?. | 200, 400, 401, 403 |
| GET | `/api/pricing/quotes/{quoteId}` | pricing:quote | quoteId path. | 200, 401, 403, 404, 410 |
| DELETE | `/api/pricing/quotes/{quoteId}` | pricing:quote | quoteId path; soft delete. | 204, 401, 403, 404 |
| GET | `/api/pricing/{vehicleId}` | Deprecated compatibility | vehicleId path; static demo compatibility endpoint. | 200, 404 |

## Confirmed request example
```json
{
  "vehicleId": "40000000000000000000000000000004",
  "vehiclePrice": "42000.00",
  "downPayment": "5000.00",
  "residualValue": "8000.00",
  "currency": "EUR",
  "durationMonths": 48,
  "annualInterestRateBasisPoints": 425
}
```
Demo policy: duration 12..72 months; annual nominal rate 0..2000 basis points. Successful response includes quoteId, calculated amounts, created/expiry timestamps and exact policy ID/version. Exact calculated-amount property names are defined by the response DTO.

## Health and docs
- `GET /health` — liveness.
- `GET /ready` — PostgreSQL readiness.
- Existing local Swagger: http://localhost:8082/api/doc
- Existing OpenAPI JSON: http://localhost:8082/api/doc.json
