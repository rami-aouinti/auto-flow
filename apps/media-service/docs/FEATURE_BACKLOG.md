# Media Processing Service — Feature backlog

**Not implemented. Planned scope only.**

## Functional capabilities
- [ ] Define allowed media types, file-size limits and ownership boundaries.
- [ ] Add private object storage, upload authorization and expiring download URLs.
- [ ] Validate file signatures, scan malware and quarantine suspicious content.
- [ ] Generate responsive thumbnails and optimized WebP/AVIF derivatives.
- [ ] Extract metadata, deduplicate by checksum and support lifecycle deletion.
- [ ] Add CDN-ready delivery, cache invalidation and transformation observability.

## API, security and quality
- [ ] Define owned upload, metadata, transform and delete use cases; no business routes exist yet.
- [ ] Add Keycloak JWT, audience, tenant and scope validation to business endpoints.
- [ ] Add OpenAPI, unit/integration/contract/E2E/security tests and load tests.
- [ ] Use outbox/inbox for asynchronous transformations, retries and DLQ.
- [ ] Add OpenTelemetry, retention, privacy controls and Kubernetes resource limits/probes.
