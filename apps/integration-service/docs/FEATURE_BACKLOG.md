# Integration Service — Remaining backlog

**Implemented baseline:** signed inbound webhooks, tenant mapping, replay/idempotency protection, outbound webhook queue, exact-host HTTPS allowlist, HMAC signing, DNS public-IP validation/pinning, TLS verification, no redirects, bounded retries and delivery tracking.

## Remaining provider capabilities
- [ ] Formal provider adapter interface and provider registry with versioned contracts.
- [ ] OAuth2 authorization-code and client-credentials flows, refresh and credential rotation.
- [ ] Provider-specific adapters for Stripe, DocuSign and CRM/ERP systems without taking ownership of their domain aggregates.
- [ ] Provider-specific rate-limit handling and Retry-After parsing.
- [ ] Circuit breaker state and half-open recovery per provider.
- [ ] Reconciliation jobs and operator replay/cancel workflows.

## Reliability and operations
- [x] Durable inbound webhook deduplication by tenant/provider/event ID.
- [x] Outbound delivery queue and bounded exponential retry worker.
- [x] Restricted worker database role and HTTPS SSRF controls.
- [ ] Transactional outbox for delivery enqueue and durable broker handoff.
- [ ] OpenTelemetry traces, delivery latency/failure metrics and alert rules.
- [ ] Kubernetes manifests, resource limits and autoscaling policy.

## Quality gates
- [x] HTTP smoke checks for health, authentication and invalid webhook signatures.
- [x] Per-service endpoint reference and central OpenAPI 3.0/Swagger contracts.
- [x] PHP syntax lint and OpenAPI route-coverage validation in GitHub Actions.
- [x] Inbound webhook body-size limit and event-type validation.
- [x] Per-service endpoint reference and central OpenAPI 3.0/Swagger contracts.
- [x] PHP syntax lint and OpenAPI route-coverage validation in GitHub Actions.
- [x] Inbound webhook body-size limit and event-type validation.
- [ ] Automated PHP unit/integration tests for HMAC, retries, DNS rebinding and tenant isolation.
- [ ] Provider contract tests using signed fixtures.
- [ ] Load tests and chaos tests for provider outages.
