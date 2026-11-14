-- Additive Document Service hardening migration. Safe to reapply.
SET ROLE autoflow_document;
SET search_path TO document_service, public;

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS legal_hold BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS legal_hold_reason TEXT,
    ADD COLUMN IF NOT EXISTS legal_hold_set_by VARCHAR(255),
    ADD COLUMN IF NOT EXISTS legal_hold_set_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS purged_at TIMESTAMPTZ;

ALTER TABLE document_access_grants
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_document_access_grants_subject
    ON document_access_grants (tenant_id, subject_id, document_id)
    WHERE revoked_at IS NULL;

CREATE TABLE IF NOT EXISTS document_purge_events (
    id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    document_id UUID NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    purged_by VARCHAR(255) NOT NULL DEFAULT 'document-retention-worker',
    purged_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    reason VARCHAR(64) NOT NULL
);
ALTER TABLE document_purge_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE document_purge_events FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS document_purge_tenant_isolation ON document_purge_events;
CREATE POLICY document_purge_tenant_isolation ON document_purge_events
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));
GRANT SELECT, INSERT ON document_purge_events TO autoflow_document;

RESET ROLE;
