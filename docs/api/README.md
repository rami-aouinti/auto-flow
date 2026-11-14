# AutoFlow API Documentation Center

Open Swagger UI at **http://localhost:8096** after starting Docker Compose.

The central OpenAPI 3.0 catalog contains the HTTP endpoints for all API-bearing services. Each service also has a detailed endpoint reference at `apps/<service>/docs/API.md`, including arguments, headers, request-body fields known from the implementation documentation, authorization requirements and possible HTTP responses.

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
- [Notification Service](../../apps/notification-service/docs/API.md) — RabbitMQ worker; no public REST API

## Start

```bash
docker compose --env-file .env.example up -d --build api-docs
```

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
