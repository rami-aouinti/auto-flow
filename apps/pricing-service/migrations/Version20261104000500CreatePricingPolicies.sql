CREATE TABLE IF NOT EXISTS pricing_policies (
    policy_id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    version INTEGER NOT NULL CHECK (version > 0),
    status VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'retired')),
    minimum_duration_months SMALLINT NOT NULL,
    maximum_duration_months SMALLINT NOT NULL,
    minimum_annual_rate_bps INTEGER NOT NULL,
    maximum_annual_rate_bps INTEGER NOT NULL,
    quote_validity_days SMALLINT NOT NULL DEFAULT 7,
    calculation_version VARCHAR(64) NOT NULL,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMPTZ NULL,
    CONSTRAINT pricing_policies_duration_range CHECK (
        minimum_duration_months >= 12 AND maximum_duration_months <= 72 AND maximum_duration_months >= minimum_duration_months
    ),
    CONSTRAINT pricing_policies_rate_range CHECK (
        minimum_annual_rate_bps >= 0 AND maximum_annual_rate_bps <= 2000 AND maximum_annual_rate_bps >= minimum_annual_rate_bps
    ),
    CONSTRAINT pricing_policies_validity_range CHECK (quote_validity_days BETWEEN 1 AND 90),
    CONSTRAINT pricing_policies_supported_calculation CHECK (calculation_version = 'lease-annuity-v1'),
    CONSTRAINT pricing_policies_published_at_consistency CHECK (
        (status = 'draft' AND published_at IS NULL)
        OR (status IN ('active', 'retired') AND published_at IS NOT NULL)
    ),
    CONSTRAINT pricing_policies_tenant_version_unique UNIQUE (tenant_id, version),
    CONSTRAINT pricing_policies_tenant_policy_unique UNIQUE (tenant_id, policy_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_pricing_policies_one_active_per_tenant
    ON pricing_policies (tenant_id)
    WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_pricing_policies_tenant_version
    ON pricing_policies (tenant_id, version DESC);

ALTER TABLE pricing_policies ENABLE ROW LEVEL SECURITY;
ALTER TABLE pricing_policies FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS pricing_policies_tenant_isolation ON pricing_policies;
CREATE POLICY pricing_policies_tenant_isolation ON pricing_policies
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));
GRANT SELECT, INSERT, UPDATE ON TABLE pricing_policies TO autoflow_pricing;
