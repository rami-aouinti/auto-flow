# AI Service — API Reference

**Base URL:** http://localhost:8095 (gateway: http://localhost:8088/api/v1/ai)  
**Provider:** Local Ollama. No API key or paid cloud account required.  
**Authentication:** Currently local-only; JWT/tenant authorization is not enforced. Do not expose this service to shared or public networks.

| Method | Path | Access | Arguments / body | Responses |
|---|---|---|---|---|
| POST | `/api/v1/ai/chat` | Local-only | JSON required: messages[]; each item has role (system, user, assistant) and content (string). | 200, 400, 422, 429, 502, 503 |

## Confirmed request example
```json
{
  "messages": [
    {"role": "system", "content": "You are the AutoFlow vehicle assistant."},
    {"role": "user", "content": "Explain the difference between leasing and financing."}
  ]
}
```

Successful response includes generated content, provider/model metadata and token counts when reported by the local runtime. Exact response property names are defined by the response DTO.

## Health
- `GET /health/ready` — verifies Ollama responds and configured model is installed.
- Ollama API is local at http://localhost:11434. Keep it private.
