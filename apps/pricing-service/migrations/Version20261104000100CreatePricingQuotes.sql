CREATE TABLE IF NOT EXISTS pricing_quotes (
    quote_id CHAR(32) PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    vehicle_id VARCHAR(100) NOT NULL,
    monthly_amount_minor BIGINT NOT NULL,
    total_installments_minor BIGINT NOT NULL,
    total_cost_minor BIGINT NOT NULL,
    residual_value_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    duration_months SMALLINT NOT NULL,
    annual_interest_rate_basis_points INTEGER NOT NULL,
    calculation_version VARCHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pricing_quotes_tenant_id_format
        CHECK (tenant_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CONSTRAINT pricing_quotes_vehicle_id_format
        CHECK (vehicle_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$'),
    CONSTRAINT pricing_quotes_monthly_amount_non_negative
        CHECK (monthly_amount_minor >= 0),
    CONSTRAINT pricing_quotes_total_installments_non_negative
        CHECK (total_installments_minor >= 0),
    CONSTRAINT pricing_quotes_total_cost_non_negative
        CHECK (total_cost_minor >= 0),
    CONSTRAINT pricing_quotes_residual_value_non_negative
        CHECK (residual_value_minor >= 0),
    CONSTRAINT pricing_quotes_currency_format
        CHECK (currency ~ '^[A-Z]{3}$'),
    CONSTRAINT pricing_quotes_duration_range
        CHECK (duration_months BETWEEN 1 AND 120),
    CONSTRAINT pricing_quotes_interest_rate_non_negative
        CHECK (annual_interest_rate_basis_points >= 0)
);

CREATE INDEX IF NOT EXISTS idx_pricing_quotes_tenant_created
    ON pricing_quotes (tenant_id, created_at DESC);

ALTER TABLE pricing_quotes ENABLE ROW LEVEL SECURITY;
ALTER TABLE pricing_quotes FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS pricing_quotes_tenant_isolation ON pricing_quotes;

CREATE POLICY pricing_quotes_tenant_isolation ON pricing_quotes
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));
