# Search Service — Remaining backlog

**Implemented:** authenticated vehicle search, autocomplete, catalogue projection, atomic index alias switching, RabbitMQ Outbox consumption, Elasticsearch inbox deduplication, delayed retry and dead-letter routing.

- [ ] Replace full-catalogue rebuild per event with incremental aggregate-aware upserts/deletes and parent-aggregate fan-out.
- [ ] Add automated retention and cleanup policy for old index generations after the rollback window.
- [ ] Add a DLQ inspection/replay command or operational UI with safe replay controls.
- [ ] Add relevance tuning, synonyms and language-specific analyzers based on measured search behavior.
- [ ] Add tenant-owned projections only when data ownership, PII minimization and tenant filters are specified per bounded context.
- [ ] Add contract and integration tests against real PostgreSQL, RabbitMQ and Elasticsearch containers, plus scope/security tests.
- [ ] Add load tests and documented latency/throughput targets.
- [ ] Add Prometheus business metrics for query latency, event lag, reindex duration and indexing failures.
- [ ] Connect frontend search UX and document the supported search scopes.
