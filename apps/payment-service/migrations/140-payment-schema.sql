CREATE TABLE IF NOT EXISTS payments (
    tenant_id VARCHAR(128) NOT NULL CHECK (tenant_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    payment_id UUID NOT NULL,
    order_id UUID NOT NULL,
    created_by VARCHAR(255) NOT NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'stripe',
    provider_payment_id VARCHAR(128) NULL,
    amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
    currency CHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    status VARCHAR(32) NOT NULL CHECK (status IN ('pending','requires_action','authorized','captured','failed','cancelled','partially_refunded','refunded')),
    amount_refunded_minor BIGINT NOT NULL DEFAULT 0 CHECK (amount_refunded_minor >= 0 AND amount_refunded_minor <= amount_minor),
    client_secret TEXT NULL,
    failure_code VARCHAR(100) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, payment_id),
    UNIQUE (provider, provider_payment_id)
);
CREATE INDEX IF NOT EXISTS idx_payments_tenant_order ON payments (tenant_id, order_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payments_tenant_status ON payments (tenant_id, status, created_at DESC);

CREATE TABLE IF NOT EXISTS payment_idempotency (
    tenant_id VARCHAR(128) NOT NULL,
    actor VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    payment_id UUID NOT NULL,
    response_json JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor, idempotency_key),
    FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, payment_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS payment_refunds (
    tenant_id VARCHAR(128) NOT NULL,
    refund_id UUID NOT NULL,
    payment_id UUID NOT NULL,
    provider_refund_id VARCHAR(128) NULL,
    amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
    status VARCHAR(24) NOT NULL CHECK (status IN ('pending','succeeded','failed')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, refund_id),
    FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, payment_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    provider_event_id VARCHAR(128) PRIMARY KEY,
    tenant_id VARCHAR(128) NULL,
    event_type VARCHAR(128) NOT NULL,
    processed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payment_outbox (
    event_id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    payment_id UUID NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMPTZ NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, payment_id) ON DELETE RESTRICT
);

DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['payments','payment_idempotency','payment_refunds','payment_outbox'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format('DROP POLICY IF EXISTS %I ON %I', t || '_tenant_isolation', t);
        EXECUTE format('CREATE POLICY %I ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)) WITH CHECK (tenant_id = current_setting(''app.tenant_id'', true))', t || '_tenant_isolation', t);
    END LOOP;
END
$$;

GRANT SELECT, INSERT, UPDATE ON payments TO autoflow_payment;
GRANT SELECT, INSERT ON payment_idempotency, payment_refunds, payment_outbox TO autoflow_payment;
GRANT SELECT, INSERT ON payment_webhook_events TO autoflow_payment;
