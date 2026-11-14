ALTER TABLE configuration_service.configuration_outbox_events
    ADD COLUMN IF NOT EXISTS next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
CREATE INDEX IF NOT EXISTS idx_configuration_outbox_due
    ON configuration_service.configuration_outbox_events (next_attempt_at, occurred_at)
    WHERE published_at IS NULL;
