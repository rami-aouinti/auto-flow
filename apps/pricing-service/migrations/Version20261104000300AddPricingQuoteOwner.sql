ALTER TABLE pricing_quotes
    ADD COLUMN IF NOT EXISTS created_by VARCHAR(255) NOT NULL DEFAULT 'legacy:unassigned';

CREATE INDEX IF NOT EXISTS idx_pricing_quotes_tenant_owner_created
    ON pricing_quotes (tenant_id, created_by, created_at DESC)
    WHERE deleted_at IS NULL;
