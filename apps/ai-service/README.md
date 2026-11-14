# AutoFlow AI Service

The AI Service is the provider-integration boundary for AutoFlow. It owns communication with model providers and returns a provider-neutral response contract. Business decisions and authoritative vehicle/pricing data remain owned by their respective services.

## Local provider

The default local provider is **Ollama**. No API key or paid cloud account is required. Docker Compose starts Ollama, downloads the configured model into the persistent `ollama-data` volume, and starts the Symfony AI service after the model is available.

Defaults:

- `OLLAMA_BASE_URI=http://ollama:11434`
- `OLLAMA_MODEL=qwen2.5:3b`
- Ollama API: `http://localhost:11434` from the host
- AI API: `http://localhost:8095`

Change `OLLAMA_MODEL` in the root `.env` to select another model. The model is downloaded on first startup and may require several GB of disk space and sufficient RAM. Model files remain in the Docker volume across container recreations.

## API

`POST /api/v1/ai/chat`

```json
{
  "messages": [
    {"role": "system", "content": "You are the AutoFlow vehicle assistant."},
    {"role": "user", "content": "Explain the difference between leasing and financing."}
  ]
}
```

The response includes generated content, provider/model metadata and token counts when reported by the local runtime.

## Health and operations

- `/health/ready` checks that Ollama responds and that the configured model is installed.
- Ollama model downloads are performed by the one-shot `ollama-model-init` Compose service.
- `ollama-data` is persistent. Do not remove it with `docker compose down -v` unless you intentionally want to delete downloaded models.

## Security

The local Ollama endpoint is bound to loopback on the host and is not routed through the public Traefik gateway. Keep it private; do not expose the unauthenticated local model API to the internet. Before routing AI requests to tenants, add service-specific Keycloak audience/scopes, tenant-aware authorization, per-tenant quotas, usage persistence, request correlation, retry policy and provider timeout/circuit-breaker policy.
