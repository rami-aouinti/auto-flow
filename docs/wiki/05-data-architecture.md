# 05 — Data architecture and consistency

## 1. Data ownership

The target microservice principle is **database per service** in the logical sense: each service owns its data model and controls access to it.

This does not require one physical database server per service. Several service-owned databases can run on one PostgreSQL cluster while remaining isolated through credentials, database/schema boundaries, migrations and access policies.

Another service must not bypass the owner and write directly into its tables.

**Current status:** PostgreSQL is provisioned in Docker Compose. One PostgreSQL container does not prove service-level database isolation and migrations are complete.

## 2. PostgreSQL — transactional source of truth

PostgreSQL is appropriate for relational business state requiring constraints and transactions: orders, customer/contract references subject to privacy boundaries, quote snapshots where auditability requires them, Outbox records and integration state.

Use database constraints as a final integrity layer, while keeping business invariants in the domain/application model too.

A local transaction should protect one service's own consistency boundary. Avoid holding a database transaction open while waiting for a remote HTTP call or human decision. A remote call inside a transaction creates long locks and still cannot make the remote operation atomic with the local database.

## 3. Redis — ephemeral and fast access

Redis can support cache entries, rate-limit counters, short-lived coordination data and temporary locks where failure semantics are understood.

It should not silently become the only source of truth for critical business records unless durability, replication, backup and recovery are explicitly designed.

Define cache TTLs, invalidation events and acceptable staleness. Cache invalidation is a consistency concern, not merely a performance detail.

## 4. Elasticsearch — search and indexing

Elasticsearch is suited to search-oriented read models: full-text vehicle search, facets, filters, relevance scoring and denormalized search documents.

It should generally be treated as a derived index, not the authoritative store for transactional order or contract state.

A robust indexing flow:
1. The service commits authoritative state.
2. It reliably publishes an integration event, for example through an Outbox.
3. An indexer consumes the event.
4. The indexer updates the search document idempotently.
5. Failed indexing is retried and observable.
6. A rebuild can reconstruct the index from authoritative sources.

Search is eventually consistent. The UI and business process must account for possible delay.

## 5. Consistency models

- **Strong local consistency:** a service transaction enforces its own invariants.
- **Eventual consistency:** other services and read models converge after event delivery.
- **Read-your-writes:** a client may need to see its own update before all projections refresh.
- **Stale reads:** search results may lag behind authoritative state.
- **Reconciliation:** periodic comparison detects missed or inconsistent downstream state.

Do not promise global strong consistency across independent services without a concrete mechanism and cost analysis.

## 6. Outbox and idempotent consumers

An Outbox record is written in the same local transaction as the aggregate change. It should have a unique event ID, aggregate ID, event type, schema version, timestamp and minimal payload, plus publication/retry state appropriate to the implementation.

A consumer can use a stable event ID and a unique processed-message record. In one local transaction, it checks/inserts the marker and applies the business change, then acknowledges only after commit.

External side effects may require provider idempotency keys or a separate state machine.

These are target patterns; their implementation must be verified in the codebase.

## 7. Schema and contract evolution

Database migrations and event/API schemas evolve independently.

- Prefer additive changes first.
- Deploy consumers that understand old and new formats before producers emit the new format.
- Do not remove fields while old consumers still depend on them.
- Version events when compatibility cannot be maintained.
- Test representative old and new payloads.
- Keep migration rollback and forward-fix strategies explicit.

## 8. Backup, restore and retention

A production data plan defines backup frequency, retention, point-in-time recovery, restore drills, encryption, access control, deletion and retention obligations, index rebuild procedures, RPO and RTO.

Local Docker volumes are development persistence, not a production backup strategy.