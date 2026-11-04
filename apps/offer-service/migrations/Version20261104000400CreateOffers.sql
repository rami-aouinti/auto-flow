CREATE TABLE IF NOT EXISTS offers (
    tenant_id VARCHAR(128) NOT NULL,
    offer_id VARCHAR(64) NOT NULL,
    created_by VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL,
    quote_id VARCHAR(64) NOT NULL,
    pricing_snapshot JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (tenant_id, offer_id),
    CONSTRAINT offers_tenant_id_format
        CHECK (tenant_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CONSTRAINT offers_status_valid
        CHECK (status IN ('draft', 'published', 'accepted', 'rejected', 'expired', 'withdrawn')),
    CONSTRAINT offers_expiration_after_creation
        CHECK (expires_at > created_at)
);

CREATE INDEX IF NOT EXISTS idx_offers_tenant_created
    ON offers (tenant_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_offers_tenant_quote
    ON offers (tenant_id, quote_id);

ALTER TABLE offers ENABLE ROW LEVEL SECURITY;
ALTER TABLE offers FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS offers_tenant_isolation ON offers;

CREATE POLICY offers_tenant_isolation ON offers
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));

GRANT SELECT, INSERT, UPDATE ON TABLE offers TO autoflow_offer;
