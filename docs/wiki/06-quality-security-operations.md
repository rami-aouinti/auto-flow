# 06 — Quality, security and operations

## 1. Quality as a continuous process

Quality is built into design, implementation, automation and production feedback.

The repository includes tooling for:
- PHPUnit — automated tests.
- PHPStan — static analysis.
- PHP-CS-Fixer — automated formatting.
- PHP_CodeSniffer — coding-standard checks.
- PHPMD — complexity and code-smell analysis.
- Rector — automated, rule-based refactoring.
- Composer audit — dependency vulnerability checks.
- GitHub Actions — CI workflow.

These tools answer different questions. Rector is primarily for automated refactoring and upgrade rules; PHPMD can flag complexity and design smells. Neither replaces sound design or tests.

## 2. Testing strategy

### Unit tests
Test domain rules and small application policies in isolation. They should be fast and deterministic.

### Application tests
Verify use-case orchestration, authorization and interactions with ports.

### Integration tests
Use real infrastructure where its behaviour matters: PostgreSQL constraints, RabbitMQ delivery, Redis semantics or Elasticsearch indexing.

### Contract tests
Verify HTTP and event contracts between independently deployed services. Producer and consumer must agree on fields, types, semantics and compatibility.

### End-to-end tests
Cover a small number of critical customer journeys across real components. They are valuable but slower and more operationally fragile.

Test failure paths deliberately: timeouts, duplicate messages, invalid transitions, unavailable dependencies and partial completion.

## 3. Security model

Security must be designed at every boundary.

### Authentication and authorization
Authentication answers who the caller is. Authorization answers what the caller may do. A valid token does not imply permission to access every order or customer record.

Apply least privilege to service identities, database credentials, broker users and observability access.

### Input and output
- Validate external input at boundaries.
- Use parameterized database operations.
- Avoid unsafe deserialization.
- Apply output encoding in user interfaces.
- Limit payload size and request rates.
- Do not expose stack traces or internal details to clients.

### Secrets
- Never commit real credentials or private keys.
- Use environment-specific secret injection.
- Rotate credentials and restrict access.
- Treat local defaults as development-only.
- Never publish production tokens or signing keys in logs.

### Privacy
Minimize personal data in events and telemetry. Do not attach tokens, full request bodies or sensitive customer details to traces. Define access, retention, deletion and audit requirements. Protect logs and backups as data stores.

## 4. Docker and local development

Docker Compose provides a reproducible local environment. Containers should be replaceable; persistent state belongs in explicit volumes or external services.

Production hardening should include:
- Non-root runtime where possible.
- Minimal, patched base images.
- Pinned image versions and controlled updates.
- Health checks and graceful shutdown.
- Resource limits and sensible timeouts.
- Read-only filesystems where practical.
- No unnecessary host mounts or privileged capabilities.

The local Alloy Docker socket mount and privileged cAdvisor configuration provide powerful host-level access. They are for trusted local development and require review before production use.

## 5. CI/CD and release engineering

A mature pipeline should:
1. Validate formatting and coding standards.
2. Run static analysis.
3. Run unit and integration tests.
4. Audit dependencies.
5. Build immutable service images.
6. Scan images and dependencies.
7. Publish versioned artefacts.
8. Deploy through an environment-specific process.
9. Verify health and critical behaviour.
10. Support rollback or forward-fix.

Build once and promote the same image between environments. For independent services, changes should be deployable independently where contract compatibility permits.

## 6. Observability: logs, metrics and traces

- **Logs (Loki):** discrete events and diagnostic details.
- **Metrics (Prometheus):** numeric time series such as request rate, error rate, latency and resource consumption.
- **Traces (Tempo):** a request's path across service boundaries and operation durations.

Grafana provides exploration and dashboards. Grafana Alloy collects container logs and forwards them to Loki. Prometheus scrapes configured infrastructure exporters. Symfony Offer and Pricing export traces over OTLP to Tempo.

The current tracing demo propagates W3C Trace Context across Offer → Pricing. Application metrics and continuous PHP profiling require additional instrumentation; a visible Grafana menu is not proof that a signal is collected.

Operational questions:
- Is the service healthy?
- Is the error rate increasing?
- Which dependency is slow?
- Did a message remain unprocessed?
- Are retries or failed messages increasing?
- Is a workflow stuck?
- Did the request reach the downstream service?
- Which release introduced the regression?

Define service-level indicators and objectives from real requirements and traffic, not arbitrary percentages.

## 7. Resilience and capacity

Plan for bounded connection pools, consumer backpressure, queue age alerts, graceful degradation, rate limits, overload protection, capacity testing and disaster recovery.

A queue absorbs bursts; it cannot fix a system whose consumers are permanently slower than incoming work.

## 8. Production-readiness checklist

- [ ] Clear owner and documented service boundary.
- [ ] Authentication, authorization and secret management.
- [ ] Input validation and safe error handling.
- [ ] Database migration and backup/restore strategy.
- [ ] Timeouts, retries and idempotency.
- [ ] Health/readiness checks and graceful shutdown.
- [ ] Logs, metrics, traces and useful alerts.
- [ ] Unit, integration and contract tests.
- [ ] Dependency and image vulnerability checks.
- [ ] Data retention, privacy and audit controls.
- [ ] Deployment, rollback and incident runbooks.
- [ ] Capacity and failure-mode testing.

## 9. Engineering decision discipline

For each significant choice, record context, requirements, alternatives, decision, rationale, consequences, risks, mitigations and evidence needed to revisit it.

A senior engineer does not choose the most fashionable technology. They choose the simplest option that satisfies requirements and make trade-offs explicit.