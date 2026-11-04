# AutoFlow repository instructions for GitHub Copilot

## Project context

AutoFlow is an independent vehicle-leasing and mobility platform portfolio project. It is inspired by publicly observable customer journeys only. Do not claim access to, reproduce, or infer the private architecture or proprietary data of MeinAuto.de or another company.

The repository is a PHP 8.4 / Symfony 7.4 microservices monorepo. Offer and Pricing are separate applications. Read the current README and relevant docs/wiki chapters before proposing changes.

## Architecture rules

- Apply DDD, Clean Architecture and Hexagonal Architecture pragmatically.
- Keep Domain independent from Symfony, Doctrine, HTTP, message brokers and vendor SDKs.
- Keep Application use cases dependent on ports/interfaces, not Infrastructure implementations.
- Infrastructure contains framework adapters, persistence, external clients and transport implementations.
- Controllers and transport handlers translate requests to use cases; they must not own business rules.
- Each bounded context owns its data. Never query or mutate another service's database.
- Share technical cross-cutting capabilities through CoreBundle; never place business concepts or business policies there.
- Use REST for public/resource-oriented HTTP APIs, gRPC for justified internal synchronous RPC, and messaging for asynchronous integration. Document why each communication style is used.
- For distributed workflows, explicitly consider timeouts, retries, idempotency, compensation, observability and failure recovery. Do not introduce Temporal or another platform component without a concrete workflow need and an ADR.
- Use integer minor units or an explicit Money value object for monetary amounts. Never use binary floating point for money.
- Include explicit units in technical measurements.

## Engineering rules

- Follow existing naming, directory and Composer conventions in the target service.
- Prefer constructor injection and small cohesive classes.
- Validate invariants in the domain, not only in controllers.
- Use typed DTOs at application and API boundaries; do not expose Doctrine entities directly.
- Add tests at the correct level: unit, integration, API/contract and end-to-end when justified.
- Keep migrations reversible where practical and safe for existing data.
- Do not introduce dependencies without explaining their purpose and maintenance cost.
- Do not edit generated files unless the task explicitly requires it.

## Security and privacy

- Never hardcode secrets or commit credentials.
- Do not log tokens, passwords, payment data or unnecessary personal data.
- Consider authentication, authorization, tenant isolation, input validation and rate limiting for exposed APIs.
- Never add unauthenticated production write endpoints.
- Treat external payloads as untrusted input.

## Workflow

1. Read the issue and linked epic; inspect the current code before editing.
2. State assumptions and a concise plan when the task is non-trivial.
3. Implement the smallest complete vertical slice.
4. Add/update tests and run the relevant checks.
5. Update OpenAPI, wiki and ADRs when needed.
6. Summarize changed files, exact commands/results, limitations and follow-up work.
7. Do not claim a test passed unless it was actually executed and passed.

## Documentation truthfulness

Use these labels in documentation:
- Implemented: verified in repository code/configuration.
- Demonstrated: limited proof of concept.
- Target architecture: planned, not yet implemented or operationally validated.

When documentation conflicts with code, inspect and resolve the discrepancy instead of assuming the documentation is correct.
