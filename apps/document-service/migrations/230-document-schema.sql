CREATE SCHEMA IF NOT EXISTS document_service AUTHORIZATION autoflow_document;
SET ROLE autoflow_document;
SET search_path TO document_service, public;

CREATE TABLE IF NOT EXISTS documents (
    id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    owner_id VARCHAR(255) NOT NULL,
    entity_type VARCHAR(64),
    entity_id VARCHAR(128),
    category VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    byte_size BIGINT NOT NULL CHECK (byte_size > 0),
    checksum_sha256 CHAR(64) NOT NULL,
    storage_key VARCHAR(512) NOT NULL,
    scan_status VARCHAR(24) NOT NULL DEFAULT 'pending' CHECK (scan_status IN ('pending','clean','infected','failed')),
    status VARCHAR(24) NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived','deleted')),
    current_version INTEGER NOT NULL DEFAULT 1 CHECK (current_version > 0),
    retention_until TIMESTAMPTZ,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ,
    UNIQUE (tenant_id, id),
    UNIQUE (tenant_id, checksum_sha256, storage_key)
);
CREATE INDEX IF NOT EXISTS idx_documents_tenant_created ON documents (tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_documents_entity ON documents (tenant_id, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_documents_status ON documents (tenant_id, status, scan_status);

CREATE TABLE IF NOT EXISTS document_versions (
    id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    document_id UUID NOT NULL,
    version INTEGER NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    byte_size BIGINT NOT NULL CHECK (byte_size > 0),
    checksum_sha256 CHAR(64) NOT NULL,
    storage_key VARCHAR(512) NOT NULL,
    scan_status VARCHAR(24) NOT NULL DEFAULT 'pending' CHECK (scan_status IN ('pending','clean','infected','failed')),
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, document_id, version),
    FOREIGN KEY (tenant_id, document_id) REFERENCES documents(tenant_id, id)
);
CREATE INDEX IF NOT EXISTS idx_document_versions_lookup ON document_versions (tenant_id, document_id, version DESC);

CREATE TABLE IF NOT EXISTS document_access_grants (
    id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    document_id UUID NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    subject_id VARCHAR(255) NOT NULL,
    permission VARCHAR(16) NOT NULL CHECK (permission IN ('read','manage')),
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, document_id, subject_id, permission)
);

CREATE TABLE IF NOT EXISTS document_audit_events (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    document_id UUID NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    action VARCHAR(64) NOT NULL,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_document_audit ON document_audit_events (tenant_id, document_id, occurred_at DESC);

CREATE TABLE IF NOT EXISTS document_outbox_events (
    id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    aggregate_id UUID NOT NULL,
    event_type VARCHAR(128) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    published_at TIMESTAMPTZ,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT
);
CREATE INDEX IF NOT EXISTS idx_document_outbox_pending ON document_outbox_events (occurred_at) WHERE published_at IS NULL;

GRANT USAGE ON SCHEMA document_service TO autoflow_document;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA document_service TO autoflow_document;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA document_service TO autoflow_document;
ALTER DEFAULT PRIVILEGES IN SCHEMA document_service GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO autoflow_document;
ALTER DEFAULT PRIVILEGES IN SCHEMA document_service GRANT USAGE, SELECT ON SEQUENCES TO autoflow_document;
RESET ROLE;
