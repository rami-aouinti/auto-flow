#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_ENV_FILE="${COMPOSE_ENV_FILE:-.env.example}"
if [[ ! -f "$COMPOSE_ENV_FILE" ]]; then
    echo "Compose env file not found: $COMPOSE_ENV_FILE" >&2
    exit 1
fi
COMPOSE=(docker compose --env-file "$COMPOSE_ENV_FILE")
TENANT_ID="${DEMO_TENANT_ID:-tenant-demo}"

echo "==> Starting the services required by the demo journey"
"${COMPOSE[@]}" up -d --build postgres rabbitmq keycloak pricing-service offer-service vehicle-catalog-service offer-workers vehicle-catalog-outbox-publisher

echo "==> Waiting for PostgreSQL"
for attempt in $(seq 1 60); do
    if "${COMPOSE[@]}" exec -T postgres pg_isready -U autoflow -d autoflow >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" -eq 60 ]]; then
        echo "PostgreSQL did not become ready." >&2
        exit 1
    fi
    sleep 2
done

echo "==> Applying the additive Pricing schema migration (safe for an existing volume)"
"${COMPOSE[@]}" exec -T postgres psql -v ON_ERROR_STOP=1 -U autoflow -d autoflow \
    < apps/pricing-service/migrations/Version20261106000100CreatePricingPoliciesAndQuoteSnapshots.sql

wait_for_http() {
    local url="$1"
    local label="$2"
    echo "Waiting for $label..."
    for attempt in $(seq 1 60); do
        if curl --silent --show-error --fail "$url" >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    echo "$label did not become ready: $url" >&2
    exit 1
}

wait_for_http "http://localhost:8084/health/ready" "Vehicle Catalog"
wait_for_http "http://localhost:8082/ready" "Pricing"
wait_for_http "http://localhost:8081/health/ready" "Offer"

echo "==> Loading the curated Vehicle Catalog fixtures"
"${COMPOSE[@]}" exec -T vehicle-catalog-service php bin/load-fixtures.php

echo "==> Generating the larger deterministic Vehicle Catalog dataset"
"${COMPOSE[@]}" exec -T vehicle-catalog-service php bin/generate-demo-dataset.php

echo "==> Obtaining the local Keycloak demo user's subject"
TOKEN_RESPONSE=""
for attempt in $(seq 1 30); do
    if TOKEN_RESPONSE="$(curl --silent --show-error --fail --max-time 10 -X POST \
        http://localhost:8080/realms/autoflow/protocol/openid-connect/token \
        -H 'Content-Type: application/x-www-form-urlencoded' \
        --data-urlencode 'grant_type=password' \
        --data-urlencode 'client_id=autoflow-local-client' \
        --data-urlencode 'username=demo' \
        --data-urlencode 'password=demo')"; then
        break
    fi
    sleep 2
done
if [[ -z "$TOKEN_RESPONSE" ]]; then
    echo "Could not obtain a token for the local Keycloak demo user." >&2
    exit 1
fi

DEMO_SUBJECT="$(printf '%s' "$TOKEN_RESPONSE" | python3 -c '
import base64, json, sys
token = json.load(sys.stdin)["access_token"]
part = token.split(".")[1]
claims = json.loads(base64.urlsafe_b64decode(part + "=" * (-len(part) % 4)))
print(claims["sub"])
')"
if [[ -z "$DEMO_SUBJECT" ]]; then
    echo "The Keycloak token did not contain a sub claim." >&2
    exit 1
fi

echo "==> Loading Pricing policy and quotes aligned to the seven curated catalogue variants"
"${COMPOSE[@]}" exec -T postgres psql -v ON_ERROR_STOP=1 -U autoflow -d autoflow \
    -v tenant_id="$TENANT_ID" -v created_by="$DEMO_SUBJECT" \
    < apps/pricing-service/fixtures/pricing_quotes_demo.sql

echo "==> Loading Offer lifecycle fixtures aligned to Pricing quote IDs and catalogue variant IDs"
"${COMPOSE[@]}" exec -T -e APP_ENV=dev -e DEMO_USER_SUBJECT="$DEMO_SUBJECT" \
    -e DEMO_TENANT_ID="$TENANT_ID" offer-service php bin/load-demo-fixtures.php

echo
echo "Demo fixtures loaded. No tables were truncated and no existing rows were deleted."
echo "Keycloak demo user: demo / demo"
echo "Tenant: $TENANT_ID"
