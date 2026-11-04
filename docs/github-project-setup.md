# GitHub Project setup — AutoFlow

This document defines the recommended GitHub Projects V2 configuration. The repository issue tracker and roadmap are already present; the Project board itself must be created in GitHub by an account with the required permissions.

## 1. Create the Project

1. Open the repository: https://github.com/rami-aouinti/auto-flow
2. Open **Projects** and choose **New project**.
3. Choose **Board**.
4. Name it **AutoFlow — Product Delivery**.
5. Add the repository's open issues as project items. Start with Epic #20 and child issues #21–#28.

## 2. Configure fields

| Field | Type | Values |
| --- | --- | --- |
| Status | Built-in single select | Backlog, Ready, In Progress, In Review, Done, Blocked |
| Sprint | Iteration | S0, S1, S2, … S10; use two-week iterations once dates/capacity are agreed |
| Priority | Single select | P0, P1, P2, P3 |
| Estimate | Number | Story points |
| Epic | Issue / single select | Parent capability or epic reference |
| Area | Single select | Architecture, Catalog, Pricing, Offer, Platform, API, Data, Observability, Frontend |

GitHub Projects has built-in fields and may present field type names differently depending on the current UI. Keep one source of truth for issue content; do not duplicate full descriptions in project fields.

## 3. Recommended views

### Product Roadmap
- Layout: Roadmap or Table.
- Group by: Sprint.
- Sort by: Priority, then Estimate.
- Show: Status, Priority, Sprint, Estimate, Area.

### Sprint Board
- Layout: Board.
- Group by: Status.
- Filter: current Sprint.
- Columns: Backlog → Ready → In Progress → In Review → Done; keep Blocked visible.

### Backlog by Epic
- Layout: Table.
- Group by: Epic.
- Show dependencies and priority.

### Blocked work
- Filter: Status is Blocked.
- Show linked dependencies and owner.

## 4. Sprint 1 initial assignment

- #20 — Epic: Vehicle Catalog.
- #21 — Domain language and bounded context.
- #22 — Manufacturer aggregate.
- #23 — Model and Generation.
- #24 — VehicleVariant and specifications.
- #25 — Application use cases and ports.
- #26 — PostgreSQL persistence.
- #27 — REST API and OpenAPI.
- #28 — Integration tests and quality gates.

Start with #21. Do not start persistence or controllers before the domain language and aggregate boundaries are reviewed.

## 5. Planning policy

- Use story points as relative complexity, uncertainty and effort, not as hours.
- Keep Sprint 1's goal stable; move unfinished work explicitly during review.
- Record dependencies in issue descriptions and link related issues.
- Use labels from .github/labels.yml.
- Keep the product-level roadmap in docs/product-roadmap.md.

## Automation limitation

The repository includes a workflow to create/update labels from .github/labels.yml. It runs on a push to master that changes the label configuration or can be run manually from **Actions → Sync repository labels → Run workflow**. A GitHub Project V2 board, custom fields and iteration schedule are account-level project resources and are not created by committing this file.
