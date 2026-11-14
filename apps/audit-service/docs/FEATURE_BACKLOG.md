# Audit and Compliance Service — Feature backlog

**Not implemented. This is planned scope, not a feature-completion claim.**

## Functional capabilities

- [ ] Define a versioned audit-event schema and producer allow-list.
- [ ] Consume audit events with inbox deduplication and payload fingerprints.
- [ ] Store append-only records with tenant, actor, action, resource, timestamp and correlation ID.
- [ ] Provide tenant-safe search, retention and compliance exports.
- [ ] Add tamper evidence, access logging and privileged-read auditing.
- [ ] Define privacy redaction and retention/deletion policy.

## API contract (future)

- [ ] Identify owned use cases and define exact routes; no business endpoint is implemented today.
- [ ] Define request/response schemas, pagination, validation and error codes.
- [ ] Require Keycloak JWT signature, issuer, audience, tenant and scopes on business routes.
- [ ] Publish OpenAPI 3.1 and realistic examples before exposing routes through Traefik.

## Architecture and reliability

- [ ] Decide whether owned persistence is required; if yes, provision a dedicated schema/database and least-privilege role.
- [ ] Use transactional outbox for produced events and inbox/idempotency for consumed events.
- [ ] Version event schemas and document compatibility, DLQ and replay procedures.
- [ ] Define timeout, retry, backpressure, graceful shutdown and failure recovery.
- [ ] Add correlation propagation, OpenTelemetry traces, metrics and structured logs.
- [ ] Define Kubernetes resource limits, probes and horizontal scaling strategy.

## Security and privacy

- [ ] Enforce tenant isolation at application and persistence layers.
- [ ] Apply rate limits, input validation, output minimization and safe errors.
- [ ] Redact personal data and secrets from logs, traces and event payloads.
- [ ] Threat-model service-to-service and external-provider access.

## Quality gates

- [ ] Unit and integration tests.
- [ ] Contract tests for producers, consumers and OpenAPI.
- [ ] Security tests for tenant isolation and authorization.
- [ ] End-to-end tests for real business journeys.
- [ ] Load tests with documented throughput, latency percentiles and error budgets.
- [ ] Update README, architecture decisions, runbooks and API docs.
