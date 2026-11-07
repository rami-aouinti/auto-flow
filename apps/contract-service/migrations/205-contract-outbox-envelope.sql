-- Normalize Contract Outbox messages to the same versioned integration envelope
-- used by Payment Service. The publisher remains unaware of business payload shape.
CREATE OR REPLACE FUNCTION public.contract_outbox_claim()
RETURNS TABLE (event_id UUID, event_type VARCHAR(100), payload JSONB, attempts INTEGER)
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    WITH candidate AS (
        SELECT o.tenant_id, o.event_id
        FROM public.contract_outbox o
        WHERE o.published_at IS NULL
          AND o.failed_at IS NULL
          AND o.next_attempt_at <= CURRENT_TIMESTAMP
          AND (o.locked_until IS NULL OR o.locked_until < CURRENT_TIMESTAMP)
        ORDER BY o.occurred_at
        FOR UPDATE SKIP LOCKED
        LIMIT 1
    )
    UPDATE public.contract_outbox o
    SET locked_until = CURRENT_TIMESTAMP + INTERVAL '30 seconds',
        attempts = o.attempts + 1
    FROM candidate c
    WHERE o.tenant_id = c.tenant_id AND o.event_id = c.event_id
    RETURNING
        o.event_id,
        o.event_type,
        jsonb_build_object(
            'eventId', o.event_id,
            'eventType', o.event_type,
            'schemaVersion', 1,
            'producer', 'contract-service',
            'aggregate', jsonb_build_object('type', 'Contract', 'id', o.contract_id),
            'tenantId', o.tenant_id,
            'occurredAt', o.occurred_at,
            'data', o.payload
        ),
        o.attempts
$function$;

ALTER FUNCTION public.contract_outbox_claim() OWNER TO autoflow_contract_outbox_owner;
REVOKE ALL ON FUNCTION public.contract_outbox_claim() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.contract_outbox_claim() TO autoflow_contract_publisher;
