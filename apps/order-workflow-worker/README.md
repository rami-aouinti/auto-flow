# Order Workflow Worker

A separate Go process that bridges RabbitMQ Order lifecycle events to durable Temporal workflows.

## Local runtime

The worker is started by the root Compose stack:

```bash
docker compose --env-file .env.example up -d --build order-service order-outbox-publisher order-workflow-worker
docker compose logs -f order-workflow-worker
```

Configuration:

| Variable | Default | Meaning |
|---|---|---|
| `TEMPORAL_ADDRESS` | `temporal:7233` | Temporal frontend |
| `TEMPORAL_NAMESPACE` | `default` | Temporal namespace |
| `TEMPORAL_TASK_QUEUE` | `autoflow-order-workflows` | Worker task queue |
| `RABBITMQ_HOST` | `rabbitmq` | Broker hostname |
| `RABBITMQ_PORT` | `5672` | Broker port |
| `RABBITMQ_VHOST` | `/` | Broker virtual host |

## Event flow

1. The Order Outbox publisher writes a persistent lifecycle event to `autoflow.order.events`.
2. The worker's durable queue `autoflow.order.saga.events` receives Order, Reservation, Contract and Fulfillment lifecycle routing keys.
3. `OrderCreated` starts both the lifecycle workflow (`order-lifecycle-{tenantId}-{orderId}`) and the fulfillment Saga (`order-fulfillment-saga-{tenantId}-{orderId}`). The Order repository currently emits `OrderCreated` (not `OrderSubmitted`) and carries the selected variant in `data.vehicleId`; the worker accepts both field names for compatibility. Duplicate starts are rejected by Temporal and acknowledged as already processed.
4. Subsequent lifecycle events signal that workflow. The worker acknowledges RabbitMQ only after Temporal accepts the start or signal.
5. Malformed lifecycle/Saga envelopes are dead-lettered to `autoflow.order.workflow.dead` and `autoflow.order.saga.dead` respectively; transient Temporal failures are requeued.
6. SLA alerts are published persistently with publisher confirms to `autoflow.order.workflow.alerts`.

## Durable lifecycle timers

- 48 hours: review decision (accepted, rejected or cancelled).
- 24 hours: accepted order must reach `contract_pending` or be cancelled.
- 23 hours: a physical vehicle hold expires automatically; the fulfillment Saga uses a shared 22-hour payment/contract deadline and releases the hold on timeout.

Reservation hold/confirm/release activities use deterministic event IDs so Temporal retries do not create new logical commands. The worker does not decide an order or mutate its status. The Order API remains the source of truth; Temporal waits for its events and emits SLA alerts. Alert queue messages are intentionally retained until an operational alert consumer or operator acknowledges them.

## Development checks

```bash
go mod tidy
gofmt -w ./cmd ./internal
go test ./...
go build ./cmd/worker
```

The unit suite covers workflow success, payment-failure and payment-cancellation compensation, OrderCreated event compatibility, Contract event normalization, and schema/producer validation. A full RabbitMQ + Temporal end-to-end test is still required before production rollout.
