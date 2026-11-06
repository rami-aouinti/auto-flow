CREATE TABLE IF NOT EXISTS orders (
    tenant_id VARCHAR(128) NOT NULL CHECK (tenant_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    order_id UUID NOT NULL,
    created_by VARCHAR(255) NOT NULL,
    offer_id VARCHAR(128) NOT NULL,
    quote_id VARCHAR(128) NOT NULL,
    vehicle_id VARCHAR(128) NOT NULL,
    status VARCHAR(24) NOT NULL CHECK (status IN ('draft','submitted','under_review','accepted','rejected','contract_pending','completed','cancelled')),
    offer_snapshot JSONB NOT NULL,
    quote_snapshot JSONB NOT NULL,
    vehicle_snapshot JSONB NOT NULL,
    decision_reason VARCHAR(500) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at TIMESTAMPTZ NULL,
    decided_at TIMESTAMPTZ NULL,
    PRIMARY KEY (tenant_id, order_id),
    CHECK (jsonb_typeof(offer_snapshot) = 'object'),
    CHECK (jsonb_typeof(quote_snapshot) = 'object'),
    CHECK (jsonb_typeof(vehicle_snapshot) = 'object')
);
CREATE INDEX IF NOT EXISTS idx_orders_tenant_created ON orders (tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_tenant_status_created ON orders (tenant_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_tenant_offer ON orders (tenant_id, offer_id);

CREATE TABLE IF NOT EXISTS order_history (
    history_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    order_id UUID NOT NULL,
    from_status VARCHAR(24) NULL,
    to_status VARCHAR(24) NOT NULL,
    actor VARCHAR(255) NOT NULL,
    reason VARCHAR(500) NULL,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, order_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_order_history_tenant_order ON order_history (tenant_id, order_id, occurred_at);

CREATE TABLE IF NOT EXISTS order_outbox (
    event_id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    order_id UUID NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    published_at TIMESTAMPTZ NULL,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMPTZ NULL,
    failed_at TIMESTAMPTZ NULL,
    last_error VARCHAR(500) NULL,
    FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, order_id) ON DELETE RESTRICT
);
ALTER TABLE order_outbox ADD COLUMN IF NOT EXISTS failed_at TIMESTAMPTZ NULL;
CREATE INDEX IF NOT EXISTS idx_order_outbox_pending
    ON order_outbox (next_attempt_at, occurred_at)
    WHERE published_at IS NULL AND failed_at IS NULL;

CREATE TABLE IF NOT EXISTS order_idempotency (
    tenant_id VARCHAR(128) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    order_id UUID NOT NULL,
    response_json JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, idempotency_key),
    FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, order_id) ON DELETE RESTRICT
);

DO $$
DECLARE table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['orders','order_history','order_outbox','order_idempotency'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('DROP POLICY IF EXISTS %I ON %I', table_name || '_tenant_isolation', table_name);
        EXECUTE format(
            'CREATE POLICY %I ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)) WITH CHECK (tenant_id = current_setting(''app.tenant_id'', true))',
            table_name || '_tenant_isolation', table_name
        );
    END LOOP;
END
$$;

GRANT SELECT, INSERT, UPDATE ON orders TO autoflow_order;
GRANT SELECT, INSERT ON order_history, order_outbox, order_idempotency TO autoflow_order;
GRANT USAGE, SELECT ON SEQUENCE order_history_history_id_seq TO autoflow_order;

-- SECURITY DEFINER functions let the isolated publisher claim rows across tenants
-- without granting the runtime API role BYPASSRLS or exposing customer/order tables.
CREATE OR REPLACE FUNCTION public.order_outbox_claim()
RETURNS TABLE (event_id UUID, event_type VARCHAR(80), payload JSONB, attempts INTEGER)
LANGUAGE sql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    WITH candidate AS (
        SELECT o.event_id FROM public.order_outbox o
        WHERE o.published_at IS NULL
          AND o.failed_at IS NULL
          AND o.next_attempt_at <= CURRENT_TIMESTAMP
          AND (o.locked_until IS NULL OR o.locked_until < CURRENT_TIMESTAMP)
        ORDER BY o.occurred_at
        FOR UPDATE SKIP LOCKED
        LIMIT 1
    )
    UPDATE public.order_outbox o
    SET locked_until = CURRENT_TIMESTAMP + INTERVAL '30 seconds',
        attempts = o.attempts + 1
    FROM candidate c
    WHERE o.event_id = c.event_id
    RETURNING o.event_id, o.event_type, o.payload, o.attempts
$function$;

CREATE OR REPLACE FUNCTION public.order_outbox_mark_published(p_event_id UUID)
RETURNS VOID
LANGUAGE sql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.order_outbox
    SET published_at = CURRENT_TIMESTAMP, locked_until = NULL, last_error = NULL
    WHERE event_id = p_event_id
$function$;

CREATE OR REPLACE FUNCTION public.order_outbox_schedule_retry(p_event_id UUID, p_error VARCHAR(500))
RETURNS VOID
LANGUAGE sql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.order_outbox
    SET locked_until = NULL,
        failed_at = CASE WHEN attempts >= 10 THEN CURRENT_TIMESTAMP ELSE NULL END,
        next_attempt_at = CASE
            WHEN attempts >= 10 THEN next_attempt_at
            ELSE CURRENT_TIMESTAMP + make_interval(secs => LEAST(300, POWER(2, LEAST(attempts, 8))::integer))
        END,
        last_error = p_error
    WHERE event_id = p_event_id
$function$;

DO $
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_order_outbox_owner') THEN
        CREATE ROLE autoflow_order_outbox_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;
    END IF;
END
$;
ALTER ROLE autoflow_order_outbox_owner NOLOGIN BYPASSRLS;
GRANT USAGE, CREATE ON SCHEMA public TO autoflow_order_outbox_owner;
GRANT SELECT, UPDATE ON TABLE order_outbox TO autoflow_order_outbox_owner;
ALTER FUNCTION public.order_outbox_claim() OWNER TO autoflow_order_outbox_owner;
ALTER FUNCTION public.order_outbox_mark_published(UUID) OWNER TO autoflow_order_outbox_owner;
ALTER FUNCTION public.order_outbox_schedule_retry(UUID, VARCHAR) OWNER TO autoflow_order_outbox_owner;

REVOKE ALL ON FUNCTION public.order_outbox_claim() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.order_outbox_mark_published(UUID) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.order_outbox_schedule_retry(UUID, VARCHAR) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.order_outbox_claim() TO autoflow_order;
GRANT EXECUTE ON FUNCTION public.order_outbox_mark_published(UUID) TO autoflow_order;
GRANT EXECUTE ON FUNCTION public.order_outbox_schedule_retry(UUID, VARCHAR) TO autoflow_order;
