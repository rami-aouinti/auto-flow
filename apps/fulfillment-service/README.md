# AutoFlow Fulfillment Service

Fulfillment is the bounded context responsible for physical vehicle handover after the commercial prerequisites are satisfied. It owns preparation, delivery scheduling, carrier assignment, dispatch tracking and proof of delivery. It does not own orders, customer profiles, contracts, payments, invoices or vehicle catalogue data.

## Business boundary

A fulfillment is created once per tenant/order. It becomes eligible for preparation only after both independent prerequisites have been observed:

- `PaymentCaptured` from Payment Service.
- `contract.signed.v1` from Contract Service.

The events may arrive in either order and may be delivered more than once. The application layer persists each event in a tenant-scoped Inbox and updates the prerequisite state in the same PostgreSQL transaction. A fulfillment is created idempotently using the unique tenant/order key. The RabbitMQ message is acknowledged only after the database commit.

## Event contracts and RabbitMQ

Both producers publish versioned JSON envelopes. Payment publishes `PaymentCaptured` to the durable topic exchange `autoflow.payment.events`; Contract publishes `contract.signed.v1` to `autoflow.contract.events`. Both envelopes include `eventId`, `eventType`, `schemaVersion`, `producer`, `tenantId`, `occurredAt` and `data.orderId`.

The consumer binds only the two prerequisite routing keys to the durable queue `autoflow.fulfillment.integration-events`. Invalid JSON, unsupported schema versions, unsupported event types and malformed identifiers are rejected without requeue and routed to `autoflow.fulfillment.integration-events.dlq`. Database or transient infrastructure errors are negatively acknowledged with requeue.

## Transaction and idempotency

For every delivery, Fulfillment:

1. Starts a PostgreSQL transaction and sets `app.tenant_id` locally for RLS.
2. Inserts the event into `fulfillment_event_inbox` with a unique tenant/event key.
3. Treats a repeated event ID with the same payload hash as an already processed delivery. Reuse of an event ID with a different payload is rejected.
4. Creates the fulfillment with `ON CONFLICT (tenant_id, order_id) DO NOTHING`, then locks the record with `SELECT ... FOR UPDATE`.
5. Records the payment or contract prerequisite only once. When both timestamps exist, it transitions to `ready_for_preparation`.
6. Writes the audit event and the `fulfillment.ready_for_preparation.v1` Outbox event in the same transaction.
7. Marks the Inbox row processed and commits. Only then does the consumer ACK RabbitMQ.

The unique order key and row lock make concurrent Payment/Contract deliveries safe: the second transaction observes the first transaction's committed prerequisite before evaluating readiness. A crash after commit but before ACK results in a redelivery that the Inbox deduplicates.

## Lifecycle

```text
awaiting_prerequisites
  -> ready_for_preparation
  -> preparing
  -> ready_for_dispatch
  -> scheduled
  -> in_transit
  -> delivered

in_transit -> delivery_failed -> scheduled (reschedule)
non-terminal states -> cancelled (subject to business policy)
```

## Runtime

The worker entrypoint is `bin/consume-events.php`. It requires:

- `FULFILLMENT_DATABASE_URL` using the restricted `autoflow_fulfillment` role.
- `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, and optional `RABBITMQ_VHOST`.

The publisher is a separate worker and must use the restricted `autoflow_fulfillment_publisher` role. The HTTP API and publisher are intentionally not started by this consumer process.

## Quality gates

Unit tests cover both supported event envelopes, schema version validation, producer/type matching and malformed identifiers. Database integration tests should additionally exercise PostgreSQL RLS, transaction rollback, duplicate delivery, out-of-order prerequisites and concurrent consumers before production rollout.
