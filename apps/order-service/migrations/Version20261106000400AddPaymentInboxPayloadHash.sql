ALTER TABLE order_payment_event_inbox
    ADD COLUMN IF NOT EXISTS payload_hash CHAR(64);

-- Existing inbox rows predate payload fingerprints. A zero hash is upgraded
-- atomically on the first exact redelivery; all new rows store the raw hash.
UPDATE order_payment_event_inbox
SET payload_hash = repeat('0', 64)
WHERE payload_hash IS NULL;

ALTER TABLE order_payment_event_inbox
    ALTER COLUMN payload_hash SET NOT NULL;

GRANT UPDATE (payload_hash) ON order_payment_event_inbox TO autoflow_order;
