#!/usr/bin/env sh
set -eu
BASE_URL="${INTEGRATION_BASE_URL:-http://localhost:8099}"
curl -fsS "$BASE_URL/health/live" | grep -q '"status":"ok"'
curl -fsS "$BASE_URL/health/ready" | grep -q '"status":"ok"'
code=$(curl -sS -o /tmp/integration-unauthorized.json -w '%{http_code}' "$BASE_URL/api/v1/integrations/webhook-events")
[ "$code" = "401" ]
code=$(curl -sS -o /tmp/integration-signature.json -w '%{http_code}' -X POST "$BASE_URL/webhooks/demo" -H 'Content-Type: application/json' -H 'X-Webhook-Signature: sha256=invalid' -d '{"id":"smoke-1","type":"smoke"}')
[ "$code" = "401" ]
echo "Integration smoke tests passed."
