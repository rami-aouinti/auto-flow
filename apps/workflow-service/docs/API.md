# Workflow Service API

Base URL: `http://localhost:8098`.

> **Current implementation status: health-only scaffold.** Temporal workflow definitions and business orchestration endpoints are not exposed by this service yet. The existing order workflow worker remains the owner of the current order workflow execution.

## Implemented endpoints

| Method | Path | Authentication | Parameters | Responses |
|---|---|---|---|---|
| GET | `/health` | Public | none | 200 |
| GET | `/health/live` | Public | none | 200 |
| GET | `/health/ready` | Public | Checks configured TCP dependencies, including Temporal at `temporal:7233` | 200 ready; 503 dependency unavailable |
| ANY | Other paths | — | No business API implemented | 404 Problem Details |

## Example

```bash
curl -i http://localhost:8098/health/live
curl -i http://localhost:8098/health/ready
```

## Planned API

Workflow start, signal, cancel, retry and execution-inspection APIs are not implemented and are intentionally not listed in the central OpenAPI contract. See [FEATURE_BACKLOG.md](./FEATURE_BACKLOG.md) before adding routes; do not treat planned capabilities as available endpoints.
