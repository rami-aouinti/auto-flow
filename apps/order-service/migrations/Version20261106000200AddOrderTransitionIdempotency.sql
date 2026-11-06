CREATE TABLE IF NOT EXISTS order_transition_idempotency (
    tenant_id VARCHAR(128) NOT NULL,
    actor VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    order_id UUID NOT NULL,
    response_json JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor, idempotency_key),
    FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, order_id) ON DELETE RESTRICT
);
ALTER TABLE order_transition_idempotency ENABLE ROW LEVEL SECURITY;
ALTER TABLE order_transition_idempotency FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS order_transition_idempotency_tenant_isolation ON order_transition_idempotency;
CREATE POLICY order_transition_idempotency_tenant_isolation ON order_transition_idempotency
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));
GRANT SELECT, INSERT ON order_transition_idempotency TO autoflow_order;
