DO $role$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_contract') THEN
        CREATE ROLE autoflow_contract LOGIN PASSWORD 'local-only-contract-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$role$;

ALTER ROLE autoflow_contract WITH LOGIN PASSWORD 'local-only-contract-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_contract;
GRANT USAGE ON SCHEMA public TO autoflow_contract;

CREATE TABLE IF NOT EXISTS contract_records (
    tenant_id VARCHAR(128) NOT NULL,
    contract_id UUID NOT NULL,
    order_id UUID NOT NULL,
    customer_id UUID NOT NULL,
    contract_type VARCHAR(40) NOT NULL CHECK (contract_type IN ('vehicle_purchase', 'vehicle_lease', 'vehicle_subscription')),
    contract_version INTEGER NOT NULL CHECK (contract_version > 0),
    status VARCHAR(24) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'prepared', 'awaiting_signature', 'signed', 'cancelled', 'superseded')),
    legal_snapshot JSONB NOT NULL,
    document_checksum CHAR(64) NULL,
    signature_provider VARCHAR(80) NULL,
    provider_envelope_id VARCHAR(180) NULL,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    prepared_at TIMESTAMPTZ NULL,
    signed_at TIMESTAMPTZ NULL,
    cancelled_at TIMESTAMPTZ NULL,
    PRIMARY KEY (tenant_id, contract_id),
    UNIQUE (tenant_id, order_id, contract_type, contract_version),
    CHECK (jsonb_typeof(legal_snapshot) = 'object'),
    CHECK ((status <> 'signed') OR signed_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_contract_records_tenant_status_created
    ON contract_records (tenant_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_contract_records_tenant_order
    ON contract_records (tenant_id, order_id, created_at DESC);

CREATE TABLE IF NOT EXISTS contract_audit_events (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    contract_id UUID NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, event_id),
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_contract_audit_history
    ON contract_audit_events (tenant_id, contract_id, occurred_at DESC);

CREATE TABLE IF NOT EXISTS contract_idempotency_keys (
    tenant_id VARCHAR(128) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    contract_id UUID NOT NULL,
    response_body JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor_id, idempotency_key),
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS contract_transition_idempotency (
    tenant_id VARCHAR(128) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    contract_id UUID NOT NULL,
    response_body JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor_id, idempotency_key),
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS contract_outbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    contract_id UUID NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMPTZ NULL,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMPTZ NULL,
    failed_at TIMESTAMPTZ NULL,
    last_error VARCHAR(500) NULL,
    PRIMARY KEY (tenant_id, event_id),
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_contract_outbox_pending
    ON contract_outbox (next_attempt_at, occurred_at)
    WHERE published_at IS NULL AND failed_at IS NULL;

DO $rls$
DECLARE table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'contract_records', 'contract_audit_events', 'contract_idempotency_keys', 'contract_transition_idempotency', 'contract_outbox'
    ] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('DROP POLICY IF EXISTS %I ON %I', table_name || '_tenant_isolation', table_name);
        EXECUTE format(
            'CREATE POLICY %I ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)) WITH CHECK (tenant_id = current_setting(''app.tenant_id'', true))',
            table_name || '_tenant_isolation',
            table_name
        );
    END LOOP;
END
$rls$;

GRANT SELECT, INSERT, UPDATE ON contract_records TO autoflow_contract;
GRANT SELECT, INSERT ON contract_audit_events, contract_idempotency_keys, contract_transition_idempotency, contract_outbox TO autoflow_contract;
