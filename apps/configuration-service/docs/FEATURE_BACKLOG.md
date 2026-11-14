# Configuration and Feature Management Service — Feature backlog

**Not implemented. Planned scope only.**

## Functional capabilities
- [ ] Define typed schemas and validation for each configuration namespace.
- [ ] Implement tenant-scoped reads/writes and immutable version history.
- [ ] Add feature flags, gradual rollout, targeting and kill switches.
- [ ] Publish change events and implement Redis cache invalidation.
- [ ] Add approval/audit workflow for sensitive business configuration.
- [ ] Keep passwords, API keys, private keys and tokens in a secret manager.

## API, security and quality
- [ ] Define owned configuration and flag use cases; no business routes exist yet.
- [ ] Add Keycloak JWT, audience, tenant and scope validation to business endpoints.
- [ ] Add OpenAPI, unit/integration/contract/E2E/security tests and load tests.
- [ ] Add optimistic concurrency, audit history, event versioning and replay strategy.
- [ ] Add OpenTelemetry, retention/privacy controls and Kubernetes resource limits/probes.
