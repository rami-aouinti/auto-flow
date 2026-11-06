-- Immutable, tenant-scoped audit trail for offer lifecycle changes.
CREATE TABLE IF NOT EXISTS offer_audit_events (
    event_id VARCHAR(64) PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    offer_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(48) NOT NULL,
    from_status VARCHAR(16) NULL,
    to_status VARCHAR(16) NOT NULL,
    actor_type VARCHAR(16) NOT NULL,
    actor_id VARCHAR(255) NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT offer_audit_actor_type_valid CHECK (actor_type IN ('user', 'system')),
    CONSTRAINT offer_audit_event_type_valid CHECK (
        event_type IN ('offer.created', 'offer.published', 'offer.accepted',
                       'offer.rejected', 'offer.expired', 'offer.withdrawn')
    ),
    CONSTRAINT offer_audit_to_status_valid CHECK (
        to_status IN ('draft', 'published', 'accepted', 'rejected', 'expired', 'withdrawn')
    ),
    CONSTRAINT offer_audit_from_status_valid CHECK (
        from_status IS NULL OR
        from_status IN ('draft', 'published', 'accepted', 'rejected', 'expired', 'withdrawn')
    ),
    CONSTRAINT offer_audit_offer_fk
        FOREIGN KEY (tenant_id, offer_id)
        REFERENCES offers (tenant_id, offer_id)
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_offer_audit_timeline
    ON offer_audit_events (tenant_id, offer_id, occurred_at ASC, event_id ASC);

ALTER TABLE offer_audit_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE offer_audit_events FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS offer_audit_tenant_isolation ON offer_audit_events;
CREATE POLICY offer_audit_tenant_isolation ON offer_audit_events
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));

GRANT SELECT, INSERT ON TABLE offer_audit_events TO autoflow_offer;
REVOKE UPDATE, DELETE, TRUNCATE ON TABLE offer_audit_events FROM autoflow_offer;

-- Backfill facts that are already provable from the persisted lifecycle timestamps.
-- This does not invent actors: the creator is retained for user-driven transitions.
INSERT INTO offer_audit_events (
    event_id, tenant_id, offer_id, event_type, from_status, to_status,
    actor_type, actor_id, occurred_at, metadata
)
SELECT
    md5(o.tenant_id || ':' || o.offer_id || ':created'),
    o.tenant_id, o.offer_id, 'offer.created', NULL, 'draft',
    'user', o.created_by, o.created_at,
    jsonb_build_object('quoteId', o.quote_id, 'vehicleId', o.pricing_snapshot->>'vehicleId')
FROM offers o
ON CONFLICT (event_id) DO NOTHING;

INSERT INTO offer_audit_events (
    event_id, tenant_id, offer_id, event_type, from_status, to_status,
    actor_type, actor_id, occurred_at, metadata
)
SELECT
    md5(o.tenant_id || ':' || o.offer_id || ':published'),
    o.tenant_id, o.offer_id, 'offer.published', 'draft', 'published',
    'user', o.created_by, o.published_at, '{}'::jsonb
FROM offers o
WHERE o.published_at IS NOT NULL
ON CONFLICT (event_id) DO NOTHING;

INSERT INTO offer_audit_events (
    event_id, tenant_id, offer_id, event_type, from_status, to_status,
    actor_type, actor_id, occurred_at, metadata
)
SELECT
    md5(o.tenant_id || ':' || o.offer_id || ':accepted'),
    o.tenant_id, o.offer_id, 'offer.accepted', 'published', 'accepted',
    'user', o.created_by, o.accepted_at, '{}'::jsonb
FROM offers o
WHERE o.accepted_at IS NOT NULL
ON CONFLICT (event_id) DO NOTHING;

INSERT INTO offer_audit_events (
    event_id, tenant_id, offer_id, event_type, from_status, to_status,
    actor_type, actor_id, occurred_at, metadata
)
SELECT
    md5(o.tenant_id || ':' || o.offer_id || ':rejected'),
    o.tenant_id, o.offer_id, 'offer.rejected', 'published', 'rejected',
    'user', o.created_by, o.rejected_at, '{}'::jsonb
FROM offers o
WHERE o.rejected_at IS NOT NULL
ON CONFLICT (event_id) DO NOTHING;

INSERT INTO offer_audit_events (
    event_id, tenant_id, offer_id, event_type, from_status, to_status,
    actor_type, actor_id, occurred_at, metadata
)
SELECT
    md5(o.tenant_id || ':' || o.offer_id || ':expired'),
    o.tenant_id, o.offer_id, 'offer.expired', CASE WHEN o.published_at IS NULL THEN 'draft' ELSE 'published' END, 'expired',
    'system', NULL, o.expired_at, '{}'::jsonb
FROM offers o
WHERE o.expired_at IS NOT NULL
ON CONFLICT (event_id) DO NOTHING;
