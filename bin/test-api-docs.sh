#!/usr/bin/env bash
set -euo pipefail

SWAGGER_URL="${SWAGGER_URL:-http://localhost:8096}"
GATEWAY_URL="${GATEWAY_URL:-http://localhost:8088}"
SPEC_FILE="$(mktemp)"
HEADERS_FILE="$(mktemp)"
trap 'rm -f "$SPEC_FILE" "$HEADERS_FILE"' EXIT

echo "[1/3] Checking Swagger UI at $SWAGGER_URL"
curl --fail --silent --show-error "$SWAGGER_URL/" -o /dev/null
echo "[2/3] Downloading OpenAPI document"
curl --fail --silent --show-error "$SWAGGER_URL/openapi.json" -o "$SPEC_FILE"

python3 - "$SPEC_FILE" <<'PY'
import json, re, sys
with open(sys.argv[1], encoding="utf-8") as f:
    spec = json.load(f)
assert spec.get("openapi", "").startswith("3."), "OpenAPI version missing/unsupported"
paths = spec.get("paths", {})
ops = [(path, method, op) for path, methods in paths.items()
       for method, op in methods.items()
       if method.lower() in {"get", "post", "put", "patch", "delete", "options", "head"}]
assert len(ops) >= 100, f"Expected at least 100 operations, found {len(ops)}"
assert len(spec.get("tags", [])) >= 14, "Expected all 14 microservice tags"
missing = []
for path, method, op in ops:
    if not op.get("responses"):
        missing.append(f"{method.upper()} {path}: no responses")
    declared = {(p.get("in"), p.get("name")) for p in op.get("parameters", [])}
    for name in re.findall(r"\{([^}]+)\}", path):
        if ("path", name) not in declared:
            missing.append(f"{method.upper()} {path}: missing path parameter {name}")
assert not missing, "\\n".join(missing)
assert "/api/v1/orders/{orderId}/history" in paths, "Order history route missing"
assert "security" not in paths["/api/v1/ai/chat"]["post"], "Local AI endpoint must not claim JWT auth"
assert "security" not in paths["/api/v1/payments/webhooks/stripe"]["post"], "Stripe webhook must use signature auth, not JWT"
print(f"OpenAPI OK: {len(paths)} paths, {len(ops)} operations, {len(spec.get('tags', []))} tags.")
PY

echo "[3/3] Checking gateway preflight CORS for Swagger origin"
curl --silent --show-error -D "$HEADERS_FILE" -o /dev/null -X OPTIONS \
  -H 'Origin: http://localhost:8096' \
  -H 'Access-Control-Request-Method: GET' \
  -H 'Access-Control-Request-Headers: authorization,content-type' \
  "$GATEWAY_URL/api/v1/orders"
if ! grep -qi 'access-control-allow-origin: http://localhost:8096' "$HEADERS_FILE"; then
  echo "ERROR: Gateway did not return the expected Swagger CORS header."
  cat "$HEADERS_FILE"
  exit 1
fi

echo "API documentation smoke test passed."
