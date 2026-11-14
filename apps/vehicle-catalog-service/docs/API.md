# Vehicle Catalog Service — API Reference

**Base URL:** http://localhost:8084  
**Authentication:** Keycloak RS256 JWT, audience `autoflow-catalog`. Global catalogue, not tenant-owned. Read: `catalog:read`; create/publish: `catalog:manage`.

| Method | Path | Permission | Arguments / body | Responses |
|---|---|---|---|---|
| GET | `/api/v1/manufacturers` | catalog:read | Published manufacturers. | 200 |
| GET | `/api/v1/vehicles` | catalog:read | Filters: q, manufacturerId, modelId, generationId, fuelType, powertrain, bodyStyle, transmission, minPowerKw, maxPowerKw, minSeats, maxSeats, limit (1..100), offset (0+). | 200, 400, 422 |
| GET | `/api/v1/vehicles/{variantId}` | catalog:read | variantId path; published variant detail. | 200, 404 |
| POST | `/api/v1/manufacturers` | catalog:manage | Create draft manufacturer; body fields are defined by service DTO. | 201, 400, 409, 422 |
| POST | `/api/v1/models` | catalog:manage | Create draft model; includes manufacturer relation; body fields defined by DTO. | 201, 400, 422 |
| POST | `/api/v1/generations` | catalog:manage | Create draft generation; includes model relation; body fields defined by DTO. | 201, 400, 422 |
| POST | `/api/v1/vehicles` | catalog:manage | Create draft technical variant; measurements use explicit units in field names; body fields defined by DTO. | 201, 400, 422 |
| POST | `/api/v1/vehicles/{variantId}/publish` | catalog:manage | variantId path; publish variant and non-archived ancestors. | 200, 404, 409 |

Errors use application/problem+json. Invalid data: 422; malformed pagination/filters: 400; duplicate canonical identities: 409; unknown parent references: 422; invisible/missing variants: 404. SQL/exception details are not returned.

## Health and Swagger
- `GET /health/live` — liveness.
- `GET /health/ready` — readiness.
- Local Swagger: http://localhost:8084/api/doc
- OpenAPI JSON: http://localhost:8084/api/doc.json
