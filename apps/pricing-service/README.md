# AutoFlow Pricing Service

Tenant-scoped lease quote calculation and immutable quote snapshots.

## Local Swagger UI

The local Docker Compose configuration runs Pricing in the development environment by default so the development-only Nelmio routes and Twig-based Swagger UI are available.

From the repository root:

```bash
docker compose up -d --build pricing-service
docker compose ps pricing-service
```

Open:

- Swagger UI: [http://localhost:8082/api/doc](http://localhost:8082/api/doc)
- OpenAPI JSON: [http://localhost:8082/api/doc.json](http://localhost:8082/api/doc.json)
- Liveness: [http://localhost:8082/health](http://localhost:8082/health)
- Readiness: [http://localhost:8082/ready](http://localhost:8082/ready)

If your existing `.env` file predates the Pricing Swagger settings, add:

```dotenv
PRICING_APP_ENV=dev
PRICING_APP_DEBUG=1
```

Then recreate the service:

```bash
docker compose up -d --force-recreate pricing-service
```

The service image installs development dependencies in the local Compose build (including NelmioApiDocBundle and TwigBundle). The Swagger routes and bundles remain development-only. For a production deployment, explicitly set `PRICING_APP_ENV=prod` and `PRICING_APP_DEBUG=0`; never expose the development Swagger UI publicly.

## Authenticate in Swagger

Quote operations are protected by the shared CoreBundle JWT authentication and authorization.

1. Obtain an access token from the local AutoFlow Keycloak realm using the client/user flow configured for your environment.
2. Ensure the token has the Pricing audience, a tenant delegated to the principal, and the `pricing:quote` scope.
3. Open Swagger UI and click **Authorize**.
4. Paste the raw JWT access token (do not add the `Bearer ` prefix; Swagger adds it).
5. Click **Authorize**, close the dialog, then use **Try it out** on an operation.

The OpenAPI security scheme describes HTTP Bearer JWT. Swagger UI does not mint tokens itself. A missing/invalid token returns 401; missing scope or tenant authorization returns 403.

The caller can read and delete their own quotes by default. The optional `pricing:quote:read:any` and `pricing:quote:delete:any` scopes widen those operations to the caller's authorized tenant; they do not bypass tenant isolation.

## Deterministic demo request

The local SQL fixture `fixtures/pricing_quotes_demo.sql` creates a tenant policy and quote examples. The POST request below uses the same vehicle and terms:

```http
POST http://localhost:8082/api/pricing/quotes
Authorization: Bearer <access-token>
Content-Type: application/json
```

```json
{
  "vehicleId": "vehicle-demo-001",
  "vehiclePrice": "30000.00",
  "downPayment": "5000.00",
  "residualValue": "12000.00",
  "currency": "EUR",
  "durationMonths": 48,
  "annualInterestRateBasisPoints": 450
}
```

Money values are decimal strings, not JSON floating-point numbers. The current demo policy accepts durations from 12 to 72 months and annual nominal rates from 0 to 2,000 basis points.

A successful request returns HTTP 200 with a `quoteId`, calculated amounts, creation/expiry timestamps and the exact policy ID/version used. Use that ID in the quote lookup and soft-delete operations.

## REST endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/api/pricing/quotes` | Calculate and persist an immutable quote snapshot |
| GET | `/api/pricing/quotes` | List the caller's quotes; supports `limit` and `offset` |
| GET | `/api/pricing/quotes/{quoteId}` | Retrieve a quote snapshot |
| DELETE | `/api/pricing/quotes/{quoteId}` | Soft-delete a quote |
| GET | `/api/pricing/{vehicleId}` | Deprecated static demo compatibility endpoint |
| GET | `/health` | Liveness probe (no JWT required) |
| GET | `/ready` | PostgreSQL readiness probe (no JWT required) |

Typical API errors:

- `400`: malformed JSON or invalid pagination.
- `401`: missing or invalid bearer token.
- `403`: missing required scope or tenant delegation.
- `404`: quote does not exist or is not visible to the caller.
- `410`: quote validity period has ended.
- `422`: invalid quote input or lease terms.

## Generate and validate the OpenAPI document

Inside the running Pricing container:

```bash
docker compose exec pricing-service php bin/console nelmio:apidoc:dump --format=json
docker compose exec pricing-service php bin/console debug:router | grep -E 'swagger|pricing_'
```

Or fetch the generated document from the host:

```bash
curl --fail --silent http://localhost:8082/api/doc.json | head -c 1000
```

## Production note

Swagger UI is intentionally registered only in the Symfony `dev` environment. Keep it disabled in production, set `PRICING_APP_ENV=prod` and `PRICING_APP_DEBUG=0`, and expose API documentation through a separately secured documentation environment if required.
