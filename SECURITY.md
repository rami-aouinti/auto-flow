# Security Policy

## Supported branch

Security fixes are developed against the current `master` branch. Releases and deployment artifacts should be built from reviewed commits on that branch.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability.

Use GitHub's **Report a vulnerability** action in the repository Security tab to submit a private vulnerability report. Include:
- affected service, endpoint, package or deployment component;
- impact and realistic attack prerequisites;
- reproducible steps or a minimal proof of concept;
- affected commit or release, if known;
- suggested mitigation, if available.

Do not include real customer data, access tokens, passwords, private keys or production connection strings in a report.

## Response and remediation

The maintainers will acknowledge a report, assess severity and affected versions, coordinate a fix, and publish an advisory when disclosure is appropriate. Do not publicly disclose details until a coordinated release or mitigation is available.

## Scope

The scope includes the Symfony services, CoreBundle, authentication and authorization boundaries, tenant isolation, persistence, messaging, Docker deployment, CI workflows and published artifacts.

A security scan result is a signal for triage, not proof that a vulnerability is exploitable or that the application is secure. Production readiness requires runtime verification and review of deployment-specific controls.
