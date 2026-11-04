# 04 — Communication and workflow patterns

## 1. Choose communication from the business requirement

| Pattern | Best fit | Main trade-off |
| --- | --- | --- |
| Synchronous HTTP | Caller needs an immediate answer | Availability and latency are coupled |
| Asynchronous message | Work can happen later; producers should be decoupled | Eventual consistency and operational complexity |
| Durable workflow | Process spans time, retries, timers or human/external steps | Workflow versioning and operations |
| Server-Sent Events | Client needs live updates | Connection lifecycle; not a backend job queue |

The question is not which technology is installed. It is what the caller needs to know, and when.

## 2. REST / HTTP

The current Offer → Pricing demonstration uses Symfony HttpClient.

Use HTTP when the caller needs a response to continue, the operation is reasonably short, and dependency availability can be part of the request's latency budget.

Engineering requirements:
- Define timeouts; never wait indefinitely.
- Distinguish connection failures, timeouts and business rejections.
- Validate input and output contracts.
- Propagate correlation and trace context.
- Apply retries only when safe and bounded.
- Avoid retrying non-idempotent operations blindly.
- Version contracts deliberately.

A successful HTTP response does not prove all downstream processing has completed unless the contract guarantees it.

## 3. RabbitMQ and Symfony Messenger

RabbitMQ provides broker transport. Symfony Messenger provides application-level dispatch, handlers, middleware, retry and failure-transport integration.

Typical use cases include notifications after state changes, search indexing, partner integration tasks and background processing.

Publish stable message contracts, not arbitrary serialized Doctrine entities.

### Delivery semantics

Design for at-least-once delivery. A message can be delivered again after a timeout, worker crash or acknowledgement problem.

Therefore:
- Consumers must be idempotent.
- Use stable message/event IDs.
- Make side effects deduplicated or naturally idempotent.
- Bound retries and use backoff.
- Provide a failure path for poison messages.
- Monitor queue depth and message age.
- Understand ordering guarantees rather than assuming them.

Exactly-once business effects across a database and broker do not arise from a broker setting alone.

## 4. Temporal and durable workflows

Temporal is designed for durable orchestration of long-running processes. A workflow can preserve progress across worker restarts and coordinate activities, timers, retries and signals.

A leasing process could eventually request partner checks, wait for responses, retry transient failures, pause for a manual decision and continue contract preparation.

Temporal is not a replacement for every Messenger message. Messenger suits independent asynchronous jobs and event consumption. Temporal suits a process whose progression and state must be durable and explicit.

Workflow constraints:
- Workflow code must be deterministic.
- External I/O belongs in Activities.
- Activities must be idempotent.
- Workflow history and payloads require privacy and retention controls.
- Workflow changes require versioning and compatibility planning.
- Operators need visibility into stuck, failed and waiting workflows.

**Current status:** Temporal is a target direction. The current Compose configuration does not run a Temporal server, and the demo flow does not prove a Temporal workflow is implemented.

## 5. Mercure and real-time updates

Mercure provides publish/subscribe delivery based on Server-Sent Events (SSE). Use it when a browser should receive a live update, such as an order status change.

Mercure is not a replacement for RabbitMQ:
- RabbitMQ transports backend messages and distributes work.
- Mercure delivers updates to subscribed clients.
- A backend event may trigger a Mercure publication, but the transports solve different problems.

Publisher and subscriber authorization must be explicit. Topics and payloads must not leak data.

**Current status:** Mercure is present in local Compose with separate publisher/subscriber JWT settings. Verify complete business publication and subscription flows in code before describing them as finished.

## 6. Transactional Outbox

A classic failure occurs when a service commits an order to PostgreSQL and crashes before publishing OrderCreated. Other services never learn about the order.

The Outbox pattern writes the business change and an outgoing event record in the same local database transaction. A separate publisher sends pending records to the broker.

The publisher may crash after publishing but before marking the record as published. Duplicates are therefore possible; consumers must be idempotent.

The Outbox makes the local state change and intent to publish atomic. It does not create one global ACID transaction across services.

**Current status:** a target reliability pattern, not assumed implemented.

## 7. Sagas and compensation

A multi-service process usually cannot rely on one global database transaction. A Saga coordinates local transactions across services.

If a later step fails, the process may execute a compensating business action. Compensation is not a magical rollback of history; it is a new action with its own rules.

A Saga can be orchestrated by Temporal or coordinated through events. Choose one clear owner for process progression.

## 8. Reliability patterns

- **Timeout:** bound how long a dependency can block a caller.
- **Retry:** repeat transient failures with bounded exponential backoff and jitter.
- **Circuit breaker:** stop repeatedly calling a failing dependency.
- **Bulkhead:** isolate resource pools so one dependency cannot exhaust all capacity.
- **Failure transport / dead letter:** preserve messages that cannot be processed automatically.
- **Idempotency key:** identify repeated requests that must not duplicate a business operation.
- **Rate limiting:** protect capacity and external dependencies.

Blind retries can amplify outages. Apply each pattern to a specific failure mode.