# Offer Service — API Reference

**Base URL:** http://localhost:8081  
**Authentication:** Keycloak bearer token + tenant context. Reads: `offers:read`. Create and lifecycle commands: `offers:quote`. Access is restricted to the authenticated offer creator in the current tenant.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/offers/{vehicleId}` | offers:quote | vehicleId path; JSON: vehiclePrice, downPayment, residualValue (decimal strings, 2 decimals), currency (3-letter), durationMonths (integer), annualInterestRateBasisPoints (integer). | 400, 401, 403, 404, 409, 422, 503; success creates draft |
| GET | `/api/offers` | offers:read | status? (draft/published/accepted/rejected/expired/withdrawn), search? (max 100 chars), limit? (1..100, default 20), offset? (default 0). | 200; response: items, total, limit, offset |
| GET | `/api/offers/{offerId}` | offers:read | offerId path. | 200, 401, 403, 404 |
| GET | `/api/offers/{offerId}/history` | offers:read | offerId path. Append-only events include type, previous/resulting status, actor type, timestamp and metadata. | 200, 401, 403, 404 |
| POST | `/api/offers/{offerId}/publish` | offers:quote | offerId path; no body. Draft only and before expiry. | 200, 401, 403, 404, 409 |
| POST | `/api/offers/{offerId}/accept` | offers:quote | offerId path; no body. Published only and before expiry. | 200, 401, 403, 404, 409 |
| POST | `/api/offers/{offerId}/reject` | offers:quote | offerId path; no body. Published only and before expiry. | 200, 401, 403, 404, 409 |
| POST | `/api/offers/{vehicleId}/quotes` | offers:quote | Legacy quote-only compatibility route; same lease-terms body as create; does not create Offer aggregate. | 200, 400, 401, 403, 422, 503 |

## Health
- `GET /health` — liveness, no JWT.
- `GET /health/ready` — readiness, no JWT.

## Important behavior
Invalid lifecycle transitions return 409. Unknown offers and offers outside the caller's tenant/ownership boundary return 404. New offers are draft and valid for 30 days by default. Legacy write operations reuse offers:quote; dedicated publish/accept/reject scopes are future security work.
