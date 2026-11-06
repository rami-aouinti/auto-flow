BEGIN;

CREATE TABLE IF NOT EXISTS pricing_policies (
    policy_id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    version SMALLINT NOT NULL,
    status VARCHAR(16) NOT NULL,
    minimum_duration_months SMALLINT NOT NULL,
    maximum_duration_months SMALLINT NOT NULL,
    minimum_annual_rate_bps INTEGER NOT NULL,
    maximum_annual_rate_bps INTEGER NOT NULL,
    quote_validity_days SMALLINT NOT NULL,
    calculation_version VARCHAR(64) NOT NULL,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMPTZ NULL,
    CONSTRAINT pricing_policies_tenant_id_format
        CHECK (tenant_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CONSTRAINT pricing_policies_version_positive CHECK (version > 0),
    CONSTRAINT pricing_policies_status_valid CHECK (status IN ('draft', 'active', 'retired')),
    CONSTRAINT pricing_policies_duration_range
        CHECK (minimum_duration_months >= 12
            AND maximum_duration_months <= 72
            AND maximum_duration_months >= minimum_duration_months),
    CONSTRAINT pricing_policies_rate_range
        CHECK (minimum_annual_rate_bps >= 0
            AND maximum_annual_rate_bps <= 2000
            AND maximum_annual_rate_bps >= minimum_annual_rate_bps),
    CONSTRAINT pricing_policies_validity_range CHECK (quote_validity_days BETWEEN 1 AND 90),
    CONSTRAINT pricing_policies_calculation_version
        CHECK (calculation_version = 'lease-annuity-v1'),
    CONSTRAINT pricing_policies_publication_state
        CHECK ((status = 'draft' AND published_at IS NULL)
            OR (status IN ('active', 'retired') AND published_at IS NOT NULL)),
    CONSTRAINT pricing_policies_tenant_policy_version_unique
        UNIQUE (tenant_id, policy_id, version),
    CONSTRAINT pricing_policies_tenant_version_unique
        UNIQUE (tenant_id, version)
);

CREATE INDEX IF NOT EXISTS idx_pricing_policies_tenant_status
    ON pricing_policies (tenant_id, status, version DESC);

CREATE UNIQUE INDEX IF NOT EXISTS idx_pricing_policies_one_active_per_tenant
    ON pricing_policies (tenant_id)
    WHERE status = 'active';

ALTER TABLE pricing_policies ENABLE ROW LEVEL SECURITY;
ALTER TABLE pricing_policies FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS pricing_policies_tenant_isolation ON pricing_policies;
CREATE POLICY pricing_policies_tenant_isolation ON pricing_policies
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));

GRANT SELECT, INSERT, UPDATE ON TABLE pricing_policies TO autoflow_pricing;

ALTER TABLE pricing_quotes
    ADD COLUMN IF NOT EXISTS vehicle_price_minor BIGINT NULL,
    ADD COLUMN IF NOT EXISTS down_payment_minor BIGINT NULL,
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '7 days'),
    ADD COLUMN IF NOT EXISTS policy_id UUID NULL,
    ADD COLUMN IF NOT EXISTS policy_version SMALLINT NULL;

UPDATE pricing_quotes
SET expires_at = created_at + INTERVAL '7 days'
WHERE expires_at IS NULL;

ALTER TABLE pricing_quotes
    ALTER COLUMN expires_at SET DEFAULT (CURRENT_TIMESTAMP + INTERVAL '7 days'),
    ALTER COLUMN expires_at SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'pricing_quotes_expiry_after_creation') THEN
        ALTER TABLE pricing_quotes
            ADD CONSTRAINT pricing_quotes_expiry_after_creation CHECK (expires_at > created_at);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'pricing_quotes_vehicle_price_non_negative') THEN
        ALTER TABLE pricing_quotes
            ADD CONSTRAINT pricing_quotes_vehicle_price_non_negative
            CHECK (vehicle_price_minor IS NULL OR vehicle_price_minor >= 0);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'pricing_quotes_down_payment_non_negative') THEN
        ALTER TABLE pricing_quotes
            ADD CONSTRAINT pricing_quotes_down_payment_non_negative
            CHECK (down_payment_minor IS NULL OR down_payment_minor >= 0);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'pricing_quotes_down_payment_not_above_price') THEN
        ALTER TABLE pricing_quotes
            ADD CONSTRAINT pricing_quotes_down_payment_not_above_price
            CHECK (vehicle_price_minor IS NULL OR down_payment_minor IS NULL
                OR down_payment_minor <= vehicle_price_minor);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'pricing_quotes_policy_snapshot_pair') THEN
        ALTER TABLE pricing_quotes
            ADD CONSTRAINT pricing_quotes_policy_snapshot_pair
            CHECK ((policy_id IS NULL AND policy_version IS NULL)
                OR (policy_id IS NOT NULL AND policy_version IS NOT NULL AND policy_version > 0));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'pricing_quotes_policy_snapshot_fk') THEN
        ALTER TABLE pricing_quotes
            ADD CONSTRAINT pricing_quotes_policy_snapshot_fk
            FOREIGN KEY (tenant_id, policy_id, policy_version)
            REFERENCES pricing_policies (tenant_id, policy_id, version)
            ON DELETE RESTRICT;
    END IF;
END
$$;

CREATE INDEX IF NOT EXISTS idx_pricing_quotes_tenant_expiry
    ON pricing_quotes (tenant_id, expires_at DESC);

COMMIT;
