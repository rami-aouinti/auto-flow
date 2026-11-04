ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS published_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS rejected_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS expired_at TIMESTAMPTZ NULL;

CREATE INDEX IF NOT EXISTS idx_offers_published_at
    ON offers (tenant_id, published_at DESC)
    WHERE published_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_offers_expired_at
    ON offers (tenant_id, expired_at DESC)
    WHERE expired_at IS NOT NULL;
