# Media Service API

Base URL: `http://localhost:8103`.

> **Current implementation status: health-only scaffold.** Binary upload, image transformation, metadata extraction, thumbnail generation, malware scanning and signed delivery URLs are planned capabilities, not implemented HTTP endpoints.

## Implemented endpoints

| Method | Path | Authentication | Parameters | Responses |
|---|---|---|---|---|
| GET | `/health` | Public | none | 200 |
| GET | `/health/live` | Public | none | 200 |
| GET | `/health/ready` | Public | No external dependency required by the current scaffold | 200 |
| ANY | Other paths | — | No business API implemented | 404 Problem Details |

## Example

```bash
curl -i http://localhost:8103/health/live
curl -i http://localhost:8103/health/ready
```

## Planned API

Upload, metadata, transformation, thumbnail and asset-delivery endpoints must be designed with authentication, tenant ownership, upload limits, content validation and malware scanning before implementation. They are intentionally excluded from the central OpenAPI contract until they exist.
