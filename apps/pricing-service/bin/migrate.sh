#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${DATABASE_ADMIN_URL:?Set DATABASE_ADMIN_URL to a migration-capable PostgreSQL connection URL}"

if ! command -v psql >/dev/null 2>&1; then
    echo "ERROR: psql is required to run Pricing Service migrations." >&2
    exit 1
fi

if [[ "${PRICING_BOOTSTRAP_LOCAL_ROLE:-0}" == "1" ]]; then
    echo "WARNING: creating the local-only autoflow_pricing role/password. Do not enable this in production." >&2
    psql "${DATABASE_ADMIN_URL}" -v ON_ERROR_STOP=1 -f "${ROOT_DIR}/migrations/00_create_pricing_database_role.sql"
fi

for migration in "${ROOT_DIR}"/migrations/Version*.sql; do
    [[ -f "${migration}" ]] || continue
    echo "Applying $(basename "${migration}")"
    psql "${DATABASE_ADMIN_URL}" -v ON_ERROR_STOP=1 -f "${migration}"
done

echo "Pricing Service migrations completed."
