CREATE SCHEMA IF NOT EXISTS configuration_service AUTHORIZATION autoflow_configuration;
SET ROLE autoflow_configuration;
SET search_path TO configuration_service, public;

CREATE TABLE IF NOT EXISTS configurations (
    id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    namespace VARCHAR(100) NOT NULL,
    config_key VARCHAR(150) NOT NULL,
    value JSONB NOT NULL,
    value_type VARCHAR(16) NOT NULL CHECK (value_type IN ('string','number','boolean','object','array','null')),
    description VARCHAR(500),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by VARCHAR(255) NOT NULL,
    updated_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, namespace, config_key)
);
CREATE INDEX IF NOT EXISTS idx_configurations_tenant_namespace ON configurations (tenant_id, namespace);

CREATE TABLE IF NOT EXISTS configuration_versions (
    id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    namespace VARCHAR(100) NOT NULL,
    config_key VARCHAR(150) NOT NULL,
    version INTEGER NOT NULL,
    value JSONB NOT NULL,
    value_type VARCHAR(16) NOT NULL,
    description VARCHAR(500),
    changed_by VARCHAR(255) NOT NULL,
    change_reason VARCHAR(500),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, namespace, config_key, version)
);
CREATE INDEX IF NOT EXISTS idx_configuration_versions_lookup
    ON configuration_versions (tenant_id, namespace, config_key, version DESC);

CREATE TABLE IF NOT EXISTS feature_flags (
    id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    flag_key VARCHAR(150) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    rollout_percentage SMALLINT NOT NULL DEFAULT 100 CHECK (rollout_percentage BETWEEN 0 AND 100),
    tenant_allowlist JSONB NOT NULL DEFAULT '[]'::jsonb,
    description VARCHAR(500),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by VARCHAR(255) NOT NULL,
    updated_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, flag_key)
);
CREATE INDEX IF NOT EXISTS idx_feature_flags_tenant ON feature_flags (tenant_id, flag_key);

CREATE TABLE IF NOT EXISTS configuration_audit_events (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    action VARCHAR(64) NOT NULL,
    resource_type VARCHAR(32) NOT NULL,
    resource_key VARCHAR(300) NOT NULL,
    version INTEGER,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_configuration_audit_tenant_time
    ON configuration_audit_events (tenant_id, occurred_at DESC);

CREATE TABLE IF NOT EXISTS configuration_outbox_events (
    id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    event_type VARCHAR(128) NOT NULL,
    aggregate_key VARCHAR(300) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    published_at TIMESTAMPTZ,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT
);
CREATE INDEX IF NOT EXISTS idx_configuration_outbox_pending
    ON configuration_outbox_events (occurred_at) WHERE published_at IS NULL;

GRANT USAGE ON SCHEMA configuration_service TO autoflow_configuration;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA configuration_service TO autoflow_configuration;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA configuration_service TO autoflow_configuration;
ALTER DEFAULT PRIVILEGES IN SCHEMA configuration_service
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO autoflow_configuration;
ALTER DEFAULT PRIVILEGES IN SCHEMA configuration_service
    GRANT USAGE, SELECT ON SEQUENCES TO autoflow_configuration;
RESET ROLE;
