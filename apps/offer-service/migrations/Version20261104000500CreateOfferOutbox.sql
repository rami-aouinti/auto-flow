CREATE TABLE IF NOT EXISTS offer_outbox_events (
    event_id VARCHAR(64) PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    aggregate_type VARCHAR(64) NOT NULL,
    aggregate_id VARCHAR(64) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    published_at TIMESTAMPTZ NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_error TEXT NULL,
    CONSTRAINT offer_outbox_attempt_count_non_negative CHECK (attempt_count >= 0),
    CONSTRAINT offer_outbox_event_type_valid
        CHECK (event_type IN ('OfferCreated', 'OfferPublished', 'OfferExpired'))
);

CREATE INDEX IF NOT EXISTS idx_offer_outbox_pending
    ON offer_outbox_events (next_attempt_at, occurred_at)
    WHERE published_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_offer_outbox_tenant
    ON offer_outbox_events (tenant_id, occurred_at DESC);

GRANT SELECT, INSERT, UPDATE ON TABLE offer_outbox_events TO autoflow_offer;

-- This narrowly scoped SECURITY DEFINER function reveals only tenant identifiers
-- that currently have expired, actionable offers. Offer data itself remains protected
-- by the offers table's forced tenant RLS policy.
CREATE OR REPLACE FUNCTION public.offer_expired_tenants(batch_size integer, cutoff timestamptz)
RETURNS TABLE (tenant_id varchar(128))
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    SELECT DISTINCT o.tenant_id
    FROM public.offers AS o
    WHERE o.status IN ('draft', 'published')
      AND o.expires_at <= cutoff
    ORDER BY o.tenant_id
    LIMIT GREATEST(1, LEAST(batch_size, 1000))
$function$;

REVOKE ALL ON FUNCTION public.offer_expired_tenants(integer, timestamptz) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.offer_expired_tenants(integer, timestamptz) TO autoflow_offer;
