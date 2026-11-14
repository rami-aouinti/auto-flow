# Analytics and Reporting Service — Remaining backlog

**Implemented baseline:** tenant-scoped PostgreSQL event projections, RabbitMQ consumer with dead-letter queue, idempotent ingestion, KPI overview, daily series, CSV export, JWT audience/scope checks, RLS and demo fixtures.

## Remaining functional capabilities
- [ ] Governed KPI catalogue with formal event-to-metric definitions and versioning.
- [ ] Tenant dashboards with segmentation and drill-down.
- [ ] Scheduled reports and XLSX export.
- [ ] Late-event correction/rebuild jobs and documented freshness SLOs.
- [ ] Additional business metrics for billing, refunds, delivery lead time and inventory turnover.

## Reliability and operations
- [x] RabbitMQ durable queue, dead-letter queue and idempotent event processing.
- [x] Tenant-scoped projection storage and database row-level security.
- [ ] Bounded retry/backoff and operator replay workflow for analytics consumer.
- [ ] Projection rebuild/replay command and retention/partitioning policy.
- [ ] OpenTelemetry spans, consumer lag metrics and alert rules.
- [ ] Kubernetes manifests, resource limits and autoscaling policy.

## Quality gates
- [x] HTTP smoke checks for health and unauthenticated access.
- [x] Per-service endpoint reference and central OpenAPI 3.0/Swagger contracts.
- [x] PHP syntax lint and OpenAPI route-coverage validation in GitHub Actions.
- [x] Per-service endpoint reference and central OpenAPI 3.0/Swagger contracts.
- [x] PHP syntax lint and OpenAPI route-coverage validation in GitHub Actions.
- [ ] Automated PHP unit/integration tests against PostgreSQL and RabbitMQ.
- [ ] Contract tests against every producer event schema.
- [ ] Load tests with documented throughput and latency percentiles.
