# AutoFlow AI Service

The AI Service is the provider-integration boundary for AutoFlow. It owns communication with external model providers and returns a provider-neutral response contract. Business decisions and authoritative vehicle/pricing data remain owned by their respective services.

## First vertical slice

- Symfony 7.4 / PHP 8.4.
- RoadRunner HTTP worker, following the existing AutoFlow runtime.
- Provider port and an OpenAI adapter.
- POST /api/v1/ai/chat accepts a bounded list of system/user/assistant messages.
- Provider credentials and model selection are configured through environment variables.
- Provider errors are normalized; upstream response bodies and secrets are not returned.

## Configuration

- OPENAI_API_KEY: secret API key. Inject through a secret manager in shared environments.
- OPENAI_MODEL: model identifier enabled for the project.
- OPENAI_BASE_URI: optional API base URI; defaults to the OpenAI v1 API.

Never commit a real API key.

## Local request

POST /api/v1/ai/chat
Content-Type: application/json

{
  "messages": [
    {"role": "system", "content": "You are the AutoFlow vehicle assistant."},
    {"role": "user", "content": "Explain the difference between leasing and financing."}
  ]
}

## Security / production hardening

This initial provider-integration slice is not yet exposed through the public Traefik gateway. Before routing user traffic to it, add a service-specific Keycloak audience/scopes, tenant-aware authorization, per-tenant quotas, request correlation, usage persistence, retry policy and provider timeout/circuit-breaker policy. Do not pass arbitrary user-controlled provider/model names through the API.
