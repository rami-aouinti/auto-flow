# AutoFlow API Documentation Center

Open Swagger UI at **http://localhost:8096** after starting Docker Compose.

The central OpenAPI 3.0.3 catalog contains the HTTP endpoints for all API-bearing services, including Document upload, versions, access grants and legal holds. Each service also has a detailed endpoint reference at `apps/<service>/docs/API.md`, including arguments, headers, request-body fields known from the implementation documentation, authorization requirements and possible HTTP responses.

## Services

- [Offer Service](../../apps/offer-service/docs/API.md) — http://localhost:8081
- [Pricing Service](../../apps/pricing-service/docs/API.md) — http://localhost:8082
- [Vehicle Catalog Service](../../apps/vehicle-catalog-service/docs/API.md) — http://localhost:8084
- [Order Management Service](../../apps/order-service/docs/API.md) — http://localhost:8085
- [Payment Service](../../apps/payment-service/docs/API.md) — http://localhost:8086
- [Billing Service](../../apps/billing-service/docs/API.md) — http://localhost:8087
- [Customer Service](../../apps/customer-service/docs/API.md) — http://localhost:8089
- [Contract Service](../../apps/contract-service/docs/API.md) — http://localhost:8090
- [Fulfillment Service](../../apps/fulfillment-service/docs/API.md) — http://localhost:8091
- [Vehicle Reservation Service](../../apps/vehicle-reservation-service/docs/API.md) — http://localhost:8092
- [Inventory Service](../../apps/inventory-service/docs/API.md) — http://localhost:8093
- [CRM Service](../../apps/crm-service/docs/API.md) — http://localhost:8094
- [AI Service](../../apps/ai-service/docs/API.md) — http://localhost:8095
- [Audit Service](../../apps/audit-service/docs/API.md) — http://localhost:8101
- [Configuration Service](../../apps/configuration-service/docs/API.md) — http://localhost:8104
- [Search Service](../../apps/search-service/docs/API.md) — http://localhost:8097
- [Document Service](../../apps/document-service/docs/API.md) — http://localhost:8100
- [Analytics Service](../../apps/analytics-service/docs/API.md) — http://localhost:8102 (gateway: `/api/v1/analytics`)
- [Integration Service](../../apps/integration-service/docs/API.md) — http://localhost:8099 (gateway: `/api/v1/integrations` and `/webhooks/{provider}`)
- [Media Service](../../apps/media-service/docs/API.md) — http://localhost:8103 (health-only scaffold)
- [Workflow Service](../../apps/workflow-service/docs/API.md) — http://localhost:8098 (health-only scaffold)
- [Notification Service](../../apps/notification-service/docs/API.md) — RabbitMQ worker; no public REST API

## Start

```bash
docker compose --env-file .env.example up -d --build api-docs api-gateway
```

Swagger UI itself starts independently, but **Try it out** requires the API Gateway and the target microservice to be running. Start the relevant backend service(s) as well. For Document Service, start PostgreSQL, the bootstrap job, RabbitMQ, ClamAV, MinIO and its bucket initializer, `document-service`, `document-outbox-publisher` and `document-retention-worker`. The AI endpoint is intentionally local-only and currently has no JWT/tenant authorization; do not expose it outside the development machine.

## Validate locally

Run the documentation smoke test after starting Swagger UI and the gateway:

```bash
bash bin/test-api-docs.sh
```

The script checks the Swagger page, downloads and structurally validates OpenAPI (path parameters, response declarations, service tags and the special AI/Stripe authentication rules), then verifies gateway CORS preflight for the Swagger origin. It does not mutate business data or call write endpoints.

## Authenticate

Obtain a Keycloak access token from the local realm, open Swagger's **Authorize** dialog and paste the raw token (without the `Bearer ` prefix). Use **Try it out** to execute requests.

## Notes

The service docs intentionally do not fabricate response properties or request fields absent from the service documentation. They list documented response status codes and identify any service-defined payloads that need to be kept synchronized with controllers and tests. Notification Service is a RabbitMQ worker, not an HTTP API.

## Health-only service scaffolds

Workflow and Media remain bootstrap containers. Analytics and Integration now expose tenant-aware business APIs and are routed through Traefik. See their API references and the implementation roadmap for remaining platform work.

## Analytics and Integration contract details

- [Analytics API](../../apps/analytics-service/docs/API.md): event ingestion, tenant KPI overview, daily event series and CSV export.
- [Integration API](../../apps/integration-service/docs/API.md): signed inbound webhooks, inbound event history, outbound delivery queue/status and configured provider names.
- [Central OpenAPI document](./openapi.json): OpenAPI request/response schemas, parameters, JWT security and error responses for the documented services. Document Service also serves its dedicated OpenAPI 3.1 contract at `/api/doc.json`.

For the shared health paths `/health/live` and `/health/ready`, choose direct port **8102** (Analytics) or **8099** (Integration) in Swagger's server selector. Business API paths use the gateway at port **8088**.

Inbound Integration webhooks use HMAC-SHA256 over the exact raw request body rather than a user JWT. All other Analytics and Integration business operations require the service-specific Keycloak audience, tenant claim and scopes. See the per-service API references for exact configuration and examples.


For the Contract/Billing PDF hand-off to Document Service, see [the integration and production-readiness guide](../operations/contract-billing-document-production-readiness.md).
