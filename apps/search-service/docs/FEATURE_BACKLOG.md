# Search Service — Remaining backlog

**The vehicle-catalogue search slice is implemented. The following cross-domain and operational capabilities remain.**

- [ ] Consume versioned catalogue events from RabbitMQ for near-real-time updates and delete propagation.
- [ ] Add an inbox with event ID deduplication, retry policy and dead-letter routing.
- [ ] Implement alias-based blue/green reindexing and rollback.
- [ ] Add relevance tuning, synonyms and language-specific analyzers based on measured search behavior.
- [ ] Add tenant-owned projections only when data ownership, PII minimization and tenant filters are specified per bounded context.
- [ ] Add contract and integration tests against a real Elasticsearch container, plus scope/security tests.
- [ ] Add load tests and documented latency/throughput targets.
- [ ] Add Prometheus business metrics for query latency, index lag and indexing failures.
- [ ] Connect frontend search UX and document the supported search scopes.
