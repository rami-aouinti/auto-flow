# AutoFlow k6 load tests

This directory contains an authenticated end-to-end load scenario for Offer creation. Each successful iteration calls the Offer HTTP API, which obtains a quote from Pricing and persists an Offer through the application/domain/repository path. The repository writes domain events to the transactional Outbox in the same database transaction.

## Prerequisites

- Docker Compose services are running and healthy.
- A valid local Keycloak access token with the `offers:quote` scope and tenant delegation.
- A published catalogue variant ID. Retrieve one from `GET http://localhost:8084/api/v1/vehicles` with a principal having `catalog:read`.

## Run

```bash
ACCESS_TOKEN='<keycloak-access-token>' \
VEHICLE_ID='<published-catalogue-variant-id>' \
TARGET_VUS=20 HOLD=2m \
k6 run infrastructure/load-testing/offer-create.js
```

Optional variables: `OFFER_BASE_URL`, `START_VUS`, `RAMP_UP`, `RAMP_DOWN`, `VEHICLE_PRICE`, `DOWN_PAYMENT`, `RESIDUAL_VALUE`, `DURATION_MONTHS`, `INTEREST_RATE_BPS` and `THINK_TIME_SECONDS`.

The initial thresholds are local guardrails, not production SLOs. Increase load gradually (1, 5, 20, 50 VUs), and observe Grafana, PostgreSQL, RabbitMQ, Offer Outbox pending events and Tempo traces. The scenario creates persistent business records: use a dedicated local test tenant and never point it at production.