# Temporal local development server

Docker Compose runs the official Temporal CLI development server:

- gRPC endpoint: `localhost:7233`
- Web UI: `http://localhost:8233`
- Namespace: `default`
- Task queue: `autoflow-order-workflows`
- Persistent local state: Docker volume `temporal-data`

The Go worker in `apps/order-workflow-worker` uses the official Temporal Go SDK. It registers the Order lifecycle workflow and the Order fulfillment Saga, and consumes versioned Order, Reservation, Contract and Fulfillment events from RabbitMQ. The workflow code keeps orchestration deterministic; RabbitMQ publishing and external side effects run as retryable Temporal activities.

## Start and inspect locally

```bash
docker compose --env-file .env.example up -d --build temporal order-workflow-worker
docker compose --env-file .env.example logs -f order-workflow-worker
```

Open the Temporal Web UI at `http://localhost:8233`. The local development server is for development and automated tests only. It does not provide production database topology, HA, TLS, authentication or operational guarantees. For production, deploy the supported Temporal Server with external persistence, schema lifecycle management, authentication/TLS, retention and backup policies.

## Verification

```bash
cd apps/order-workflow-worker
gofmt -w ./cmd ./internal
go test ./...
go build ./cmd/worker
```

The unit suite covers workflow success, payment-failure compensation, event decoding, producer/schema validation and deterministic event routing. The root Quality workflow builds the worker and runs its Go tests. A fully provisioned production Temporal/RabbitMQ soak test and alert-consumer operations remain rollout requirements.
