ALTER TABLE pricing_quotes ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL;

CREATE INDEX IF NOT EXISTS idx_pricing_quotes_tenant_active_created
    ON pricing_quotes (tenant_id, created_at DESC)
    WHERE deleted_at IS NULL;
