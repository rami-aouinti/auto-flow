-- Store a canonical payload fingerprint so an event ID cannot be reused
-- with different contents while still being treated as a harmless duplicate.
ALTER TABLE billing_event_inbox
    ADD COLUMN IF NOT EXISTS payload_hash CHAR(64) NOT NULL
    DEFAULT repeat('0', 64);
