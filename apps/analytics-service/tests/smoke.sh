#!/usr/bin/env sh
set -eu
BASE_URL="${ANALYTICS_BASE_URL:-http://localhost:8102}"
curl -fsS "$BASE_URL/health/live" | grep -q '"status":"ok"'
curl -fsS "$BASE_URL/health/ready" | grep -q '"status":"ok"'
code=$(curl -sS -o /tmp/analytics-unauthorized.json -w '%{http_code}' "$BASE_URL/api/v1/analytics/overview")
[ "$code" = "401" ]
echo "Analytics smoke tests passed."
