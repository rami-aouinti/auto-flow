# 11 — Integration event catalogue and workflow design

## 1. Why an event catalogue?

An event is a statement of fact: something has happened. A command is a request: something should happen.

For example:
- `OrderSubmitted` is a fact.
- `ValidateOrder` is a command.

Naming them correctly makes ownership and failure handling clearer.

This chapter defines the cross-context event model. Order Management now writes order lifecycle events to its own Transactional Outbox and publishes them to the durable RabbitMQ topic exchange `autoflow.order.events`. The downstream consumers remain a later increment; the Offer/Pricing demonstration does not yet publish its candidate business events.

## 2. Candidate event catalogue

| Event | Producer / owner | Possible consumers | Meaning |
| --- | --- | --- | --- |
| `OfferPublished` | Offer | Customer channel, analytics | An offer became available |
| `OfferExpired` | Offer | Customer channel, workflow | An offer passed its validity |
| `OrderSubmitted` | Order | Workflow, analytics | A customer submitted an order |
| `OrderAccepted` | Order | Contract, notification | The order passed acceptance rules |
| `OrderRejected` | Order | Notification, analytics | The order was rejected |
| `OrderCreated` | Order | Workflow, analytics | A draft order was created |
| `OrderUnderReview` | Order | Workflow, operations | Manual review started |
| `OrderCancelled` | Order | Workflow, analytics | The order was cancelled |
| `OrderContractPending` | Order | Contract | Contract preparation is pending |
| `OrderCompleted` | Order | Analytics, fulfilment | The order lifecycle completed |
| `ContractPrepared` | Contract | Order, notification | A contract version is ready |
| `ContractSigned` | Contract | Order, fulfilment | A signature was confirmed |
| `NotificationRequested` | Owning business context | Notification | A communication should be attempted |
| `NotificationDelivered` | Notification | Audit / reporting | Provider confirmed delivery according to its contract |
| `NotificationFailed` | Notification | Workflow / operations | Delivery failed under the defined policy |

The event owner is the service whose business fact it represents. Consumers must not redefine the meaning of another context's event.

## 3. Event envelope

A stable envelope should carry enough metadata for routing, compatibility and diagnostics without exposing unnecessary personal data.

~~~json
{
  "eventId": "01JEXAMPLEUNIQUE",
  "eventType": "OrderSubmitted",
  "schemaVersion": 1,
  "occurredAt": "2026-11-04T10:15:30Z",
  "producer": "order-service",
  "aggregate": {
    "type": "Order",
    "id": "order-123"
  },
  "correlationId": "checkout-456",
  "data": {
    "orderId": "order-123",
    "offerId": "offer-789"
  }
}
~~~

Example only. The identifiers are fictitious. Do not put full customer profiles, access tokens, payment credentials or contract contents into a broadly distributed event.

## 4. Event flow with the Transactional Outbox

~~~mermaid
flowchart LR
    API[Order API] --> TX[Local DB transaction]
    TX --> Order[(Order state)]
    TX --> Outbox[(Outbox row)]
    Outbox --> Publisher[Outbox publisher]
    Publisher --> Broker[RabbitMQ]
    Broker --> Consumer[Consumer]
    Consumer --> Dedup[(Processed event IDs)]
    Consumer --> Projection[(Consumer-owned state)]
~~~

The order update and Outbox insert commit together. The publisher sends pending records. The consumer deduplicates by event ID and updates only its own state.

The publisher can send a message and crash before recording that it was sent. A duplicate can therefore reach the consumer. The consumer must be idempotent.

## 5. Messenger responsibilities

Symfony Messenger can dispatch messages to handlers and use RabbitMQ as transport. Define separate transports and retry policies based on message characteristics rather than putting every message into one queue.

A production setup should document:
- Exchange and queue names.
- Routing keys and binding rules.
- Durable queue and message settings.
- Retry count, delay, backoff and jitter.
- Failure transport and reprocessing procedure.
- Consumer concurrency and prefetch.
- Message schema compatibility.
- Idempotency and deduplication.
- Queue age and backlog alerts.

RabbitMQ Management helps operators inspect broker state. It does not replace application-level visibility into why a handler failed or whether a business operation completed.

## 6. Temporal versus Messenger

Use Messenger for independent background jobs and message consumption. Use Temporal when a business process needs durable progression, timers, signals, retries or human/external waiting.

A possible division:
- RabbitMQ carries `OrderSubmitted`.
- A consumer starts or signals the Order workflow.
- Temporal owns long-running process progression.
- Temporal Activities call partner APIs or application services.
- Business services remain owners of their own state and invariants.
- Messenger remains suitable for independent jobs such as a search re-index task.

Do not let both Messenger and Temporal independently own the same workflow state machine.

## 7. Idempotency strategy

For an incoming command:
- Require or generate an idempotency key at the API boundary.
- Scope the key to the caller and operation.
- Store the result or operation reference.
- Reject reuse with a materially different request body.

For an incoming event:
- Use the stable event ID.
- Persist deduplication and the local state change in one transaction where possible.
- Make external side effects idempotent with provider keys or a separate delivery state machine.

## 8. Versioning and compatibility

Prefer additive event evolution. Consumers should tolerate unknown optional fields. If a breaking change is necessary, introduce a new schema version and coordinate consumer rollout. Never silently change the meaning of an existing field.

## 9. Operations and observability

For each event type, monitor:
- Published count and publish failures.
- Queue depth and oldest message age.
- Consumer success/failure rate.
- Retry count and failure transport count.
- Processing duration.
- Duplicate detection count.
- End-to-end time from event occurrence to consumer completion.

Propagate trace context through message headers where supported. Use event ID and correlation ID as structured log attributes. Do not confuse a correlation ID with a distributed trace ID.

## 10. Current Order Management event publication\n\nThe Order service records the order state change and Outbox event in one PostgreSQL transaction. Its publisher claims one pending row using `FOR UPDATE SKIP LOCKED` through narrowly scoped SECURITY DEFINER functions, publishes a persistent RabbitMQ message with publisher confirms, then marks it published. Failed publication is rescheduled with bounded exponential backoff. A crash after broker confirmation but before the database acknowledgement can produce a duplicate; consumers must deduplicate by `eventId`. The publisher declares one durable holding queue bound to the event exchange so messages remain safely queued before downstream consumers are deployed; it does not create business-specific consumer queues.\n\n## 11. Implementation gate

Before implementing an event, identify its owner, business meaning, schema, consumers, privacy classification, delivery guarantee, idempotency key, retry policy, replay policy and operational owner. An event without a clear owner is an integration risk.