ALTER TABLE pricing_quotes
    ADD COLUMN IF NOT EXISTS vehicle_price_minor BIGINT NULL,
    ADD COLUMN IF NOT EXISTS down_payment_minor BIGINT NULL,
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ;

UPDATE pricing_quotes
SET expires_at = created_at + INTERVAL '7 days'
WHERE expires_at IS NULL;

ALTER TABLE pricing_quotes
    ALTER COLUMN expires_at SET NOT NULL;

ALTER TABLE pricing_quotes
    ADD CONSTRAINT pricing_quotes_vehicle_price_non_negative
        CHECK (vehicle_price_minor IS NULL OR vehicle_price_minor >= 0),
    ADD CONSTRAINT pricing_quotes_down_payment_non_negative
        CHECK (down_payment_minor IS NULL OR down_payment_minor >= 0),
    ADD CONSTRAINT pricing_quotes_expiry_after_creation
        CHECK (expires_at > created_at);

CREATE INDEX IF NOT EXISTS idx_pricing_quotes_tenant_expiry
    ON pricing_quotes (tenant_id, expires_at)
    WHERE deleted_at IS NULL;
