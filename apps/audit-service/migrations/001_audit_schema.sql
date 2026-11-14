DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='autoflow_audit') THEN
        CREATE ROLE autoflow_audit LOGIN PASSWORD 'local-only-audit-password';
    END IF;
END $$;
GRANT CONNECT ON DATABASE autoflow TO autoflow_audit;
CREATE TABLE IF NOT EXISTS audit_tenant_heads (
    tenant_id VARCHAR(128) PRIMARY KEY,
    last_hash CHAR(64) NOT NULL,
    last_event_id VARCHAR(128) NOT NULL
);
CREATE TABLE IF NOT EXISTS audit_events (
    event_id VARCHAR(128) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    actor_type VARCHAR(16) NOT NULL CHECK(actor_type IN ('user','service','system')),
    actor_id VARCHAR(255),
    resource_type VARCHAR(80) NOT NULL,
    resource_id VARCHAR(160) NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    correlation_id VARCHAR(128),
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    previous_hash CHAR(64) NOT NULL,
    event_hash CHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(tenant_id,event_id),
    UNIQUE(tenant_id,event_hash)
);
CREATE INDEX IF NOT EXISTS idx_audit_tenant_timeline ON audit_events(tenant_id,occurred_at DESC,event_id DESC);
CREATE INDEX IF NOT EXISTS idx_audit_tenant_type ON audit_events(tenant_id,event_type,occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_tenant_actor ON audit_events(tenant_id,actor_id,occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_tenant_resource ON audit_events(tenant_id,resource_type,resource_id,occurred_at DESC);
ALTER TABLE audit_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_events FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS audit_tenant_isolation ON audit_events;
CREATE POLICY audit_tenant_isolation ON audit_events USING(tenant_id=current_setting('app.tenant_id',true)) WITH CHECK(tenant_id=current_setting('app.tenant_id',true));
ALTER TABLE audit_tenant_heads ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_tenant_heads FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS audit_heads_tenant_isolation ON audit_tenant_heads;
CREATE POLICY audit_heads_tenant_isolation ON audit_tenant_heads USING(tenant_id=current_setting('app.tenant_id',true)) WITH CHECK(tenant_id=current_setting('app.tenant_id',true));
CREATE OR REPLACE FUNCTION prevent_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'audit_events is append-only'; END;
$$;
DROP TRIGGER IF EXISTS audit_events_immutable ON audit_events;
CREATE TRIGGER audit_events_immutable BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation();
GRANT SELECT,INSERT ON audit_events TO autoflow_audit;
GRANT SELECT,INSERT,UPDATE ON audit_tenant_heads TO autoflow_audit;
REVOKE UPDATE,DELETE,TRUNCATE ON audit_events FROM autoflow_audit;
