# 17 — Engineering delivery, GitHub workflow and AI agents

## Purpose

This chapter defines how AutoFlow work moves from a product requirement to reviewed, tested and documented code. It applies to human contributors and AI coding agents.

## Work hierarchy

- **Product capability:** customer or operational outcome.
- **Epic:** a coherent business capability with a measurable outcome.
- **Issue:** one reviewable unit of work with acceptance criteria.
- **Pull request:** implementation proposal linked to one or more issues.
- **Architecture Decision Record (ADR):** durable record of a significant decision and trade-offs.
- **Sprint:** a timeboxed planning commitment. Sprint labels in issue text are descriptive until a GitHub Project iteration field is configured.

## Issue lifecycle

Backlog → Ready → In Progress → In Review → Done.  
Blocked work must be explicitly marked Blocked and returned to Ready only when its dependency is resolved.

Before work starts:
1. Read the linked epic, acceptance criteria, dependencies and relevant wiki chapters.
2. Inspect the current repository and tests. Never assume the issue description reflects current code.
3. Confirm scope and identify unknown business rules.
4. Propose a short implementation plan before broad changes.
5. Create a focused branch and keep the change limited to the issue.

Before a pull request is ready:
1. Add or update unit, integration and contract tests as appropriate.
2. Run relevant quality gates and report exact commands/results.
3. Update API documentation, wiki and ADRs when behavior or architecture changes.
4. Link the issue with GitHub closing syntax only when the PR truly completes it.
5. Explain migrations, operational impact, security impact and known limitations.

## Pull request checklist

- [ ] The change satisfies the issue acceptance criteria.
- [ ] Domain and Application layers remain framework-independent.
- [ ] No cross-service database access or shared business entities were introduced.
- [ ] Input validation, authorization and tenant context are considered.
- [ ] Timeouts, retries, duplicate messages and partial failures are handled where relevant.
- [ ] Tests cover success paths and important failure paths.
- [ ] Static analysis and coding standards pass.
- [ ] Logs contain useful context but no secrets or unnecessary personal data.
- [ ] Documentation reflects the actual implementation.
- [ ] No feature is described as production-ready without operational evidence.

## AI agent policy

AI agents are assistants, not autonomous product owners.

They must:
- Treat repository code, tests, ADRs and accepted issue criteria as the source of truth.
- Distinguish implemented behavior, proof of concept and target architecture.
- Ask for clarification when a missing business rule changes the model or behavior.
- Prefer small, reviewable changes over speculative platform-wide refactors.
- Never invent credentials, production data, API responses, benchmark results or test outcomes.
- Never weaken tests, authorization or validation merely to make a build pass.
- Never run destructive commands or modify production infrastructure without explicit approval.
- Report files changed, commands executed, results, risks and remaining work.
- Avoid copying proprietary systems or data from other companies. AutoFlow is an independent project inspired only by public customer journeys.

## Architecture escalation

Create or update an ADR when a decision changes:
- bounded-context ownership or service boundaries;
- synchronous vs asynchronous communication;
- data consistency, event delivery or workflow orchestration;
- authentication, authorization, tenant isolation or personal-data handling;
- persistence technology or data ownership;
- deployment topology or operational responsibility.

An agent should present alternatives and trade-offs rather than silently choosing a complex technology.

## Sprint conventions

Sprint names S0–S10 are planning identifiers in the product roadmap. They become actual iteration values only after the GitHub Project is configured. Estimates are relative effort, not delivery promises. A sprint is complete only when its goal is demonstrated and its acceptance criteria are verified.
