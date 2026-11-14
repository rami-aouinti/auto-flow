# Document Management Service — Feature backlog

**Not implemented. This is planned scope, not a feature-completion claim.**

## Functional capabilities

- [ ] Define metadata, ownership, tenant, classification, retention and lifecycle states.
- [ ] Add encrypted private object-storage adapter.
- [ ] Implement upload/download authorization, expiring URLs, checksums and versioning.
- [ ] Add malware scanning, MIME sniffing, size limits and quarantine.
- [ ] Add retention/legal-hold policies, deletion workflow and audit trail.
- [ ] Integrate Contract and Billing without duplicating domain decisions or PDF generation.

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
