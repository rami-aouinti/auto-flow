# AutoFlow Fulfillment Service

Fulfillment is the bounded context responsible for physical vehicle handover after the commercial prerequisites are satisfied. It owns preparation, delivery scheduling, carrier assignment, dispatch tracking and proof of delivery. It does not own orders, customer profiles, contracts, payments, invoices or vehicle catalogue data.

## Business boundary

A fulfillment is created once per tenant/order. It becomes eligible for preparation only after all three independent prerequisites have been observed:

- `PaymentCaptured` from Payment Service.
- `contract.signed.v1` from Contract Service.
- `reservation.confirmed.v1` from Vehicle Reservation Service.

The events may arrive in any order and may be delivered more than once. The application layer persists each event in a tenant-scoped Inbox and updates the prerequisite state in the same PostgreSQL transaction. A fulfillment is created idempotently using the unique tenant/order key. The RabbitMQ message is acknowledged only after the database commit.

## Event contracts and RabbitMQ

The three producers publish versioned JSON envelopes. Payment publishes `PaymentCaptured` to the durable topic exchange `autoflow.payment.events`; Contract publishes `contract.signed.v1` to `autoflow.contract.events`; Vehicle Reservation publishes `reservation.confirmed.v1` to `autoflow.vehicle-reservation.events`. All envelopes include `eventId`, `eventType`, `schemaVersion`, `producer`, `tenantId`, `occurredAt` and `data.orderId`.

The consumer binds only the three prerequisite routing keys to the durable queue `autoflow.fulfillment.integration-events`. Invalid JSON, unsupported schema versions, unsupported event types and malformed identifiers are rejected without requeue and routed to `autoflow.fulfillment.integration-events.dlq`. Database or transient infrastructure errors are negatively acknowledged with requeue.

## Transaction and idempotency

For every delivery, Fulfillment:

1. Starts a PostgreSQL transaction and sets `app.tenant_id` locally for RLS.
2. Inserts the event into `fulfillment_event_inbox` with a unique tenant/event key.
3. Treats a repeated event ID with the same payload hash as an already processed delivery. Reuse of an event ID with a different payload is rejected.
4. Creates the fulfillment with `ON CONFLICT (tenant_id, order_id) DO NOTHING`, then locks the record with `SELECT ... FOR UPDATE`.
5. Records each payment, contract or reservation prerequisite only once. When all three timestamps exist, it transitions to `ready_for_preparation`.
6. Writes the audit event and the `fulfillment.ready_for_preparation.v1` Outbox event in the same transaction.
7. Marks the Inbox row processed and commits. Only then does the consumer ACK RabbitMQ.

The unique order key and row lock make concurrent Payment/Contract/Reservation deliveries safe: the second transaction observes the first transaction's committed prerequisite before evaluating readiness. A crash after commit but before ACK results in a redelivery that the Inbox deduplicates.

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

The publisher is a separate worker and must use the restricted `autoflow_fulfillment_publisher` role. The HTTP API is served by RoadRunner on port 8091 locally. The API requires a Keycloak access token with the `autoflow-fulfillment` audience and one of the operation-specific realm roles. State-changing commands require both `Idempotency-Key` and the current `If-Match` ETag. The publisher is a separate worker and must use the restricted `autoflow_fulfillment_publisher` role.

## Quality gates

Unit tests cover both supported event envelopes, schema version validation, producer/type matching and malformed identifiers. Database integration tests should additionally exercise PostgreSQL RLS, transaction rollback, duplicate delivery, out-of-order prerequisites and concurrent consumers before production rollout.

## HTTP API

All endpoints are tenant-scoped and protected by the CoreBundle JWT verifier.

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| GET | `/api/v1/fulfillments` | `fulfillment:read` | List and filter deliveries |
| GET | `/api/v1/fulfillments/{id}` | `fulfillment:read` | Read one delivery |
| GET | `/api/v1/fulfillments/{id}/history` | `fulfillment:read` | Read audit history |
| POST | `/{id}/preparation/start` | `fulfillment:prepare` | Start preparation |
| POST | `/{id}/preparation/complete` | `fulfillment:prepare` | Complete preparation |
| POST | `/{id}/schedule` | `fulfillment:schedule` | Schedule or reschedule delivery |
| POST | `/{id}/dispatch` | `fulfillment:dispatch` | Dispatch the vehicle |
| POST | `/{id}/delivery/confirm` | `fulfillment:deliver` | Confirm handover |
| POST | `/{id}/delivery/fail` | `fulfillment:deliver` | Record failed delivery |
| POST | `/{id}/cancel` | `fulfillment:cancel` | Cancel before dispatch |

State-changing commands require `Idempotency-Key` and `If-Match: "version"`. The service locks the tenant-owned row, checks the expected version, updates the aggregate, writes the audit record and Outbox envelope, and stores the idempotent response in one transaction. A stale ETag returns HTTP 412; an invalid state transition returns HTTP 409. CORS explicitly permits `If-Match` and exposes `ETag` so browser clients can use optimistic concurrency.

The realm import file adds the `autoflow-fulfillment` audience and six `fulfillment:*` roles to the local `demo` and `order-operator` users. Keycloak does not merge realm-import changes into a realm that already exists; on an existing local realm, add the audience mapper and roles through the Admin Console or re-import the realm only after backing up its current data.
