ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(24) NOT NULL DEFAULT 'not_started';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'orders_payment_status_check'
    ) THEN
        ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check
            CHECK (payment_status IN ('not_started','authorized','captured','failed','refunded','cancelled'));
    END IF;
END
$$;

CREATE TABLE IF NOT EXISTS order_payment_event_inbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    order_id UUID NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, event_id),
    FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, order_id) ON DELETE RESTRICT
);

ALTER TABLE order_payment_event_inbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE order_payment_event_inbox FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS order_payment_event_inbox_tenant_isolation ON order_payment_event_inbox;
CREATE POLICY order_payment_event_inbox_tenant_isolation ON order_payment_event_inbox
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));

GRANT SELECT, INSERT ON order_payment_event_inbox TO autoflow_order;
GRANT UPDATE (payment_status, updated_at) ON orders TO autoflow_order;
