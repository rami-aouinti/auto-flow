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

psql "${DATABASE_ADMIN_URL}" -v ON_ERROR_STOP=1 -c "
CREATE TABLE IF NOT EXISTS pricing_schema_migrations (
    version VARCHAR(255) PRIMARY KEY,
    executed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
)"

for migration in "${ROOT_DIR}"/migrations/Version*.sql; do
    [[ -f "${migration}" ]] || continue
    version="$(basename "${migration}")"
    applied="$(psql "${DATABASE_ADMIN_URL}" -v ON_ERROR_STOP=1 -tAc "SELECT 1 FROM pricing_schema_migrations WHERE version = '${version}'")"
    if [[ "${applied}" == "1" ]]; then
        echo "Skipping already applied ${version}"
        continue
    fi
    echo "Applying ${version}"
    psql "${DATABASE_ADMIN_URL}" -v ON_ERROR_STOP=1 -f "${migration}"
    psql "${DATABASE_ADMIN_URL}" -v ON_ERROR_STOP=1 -c "INSERT INTO pricing_schema_migrations (version) VALUES ('${version}')"
done

echo "Pricing Service migrations completed."
