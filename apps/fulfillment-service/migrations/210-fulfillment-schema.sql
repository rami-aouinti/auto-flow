-- Fulfillment owns the operational delivery lifecycle. It stores references to
-- upstream aggregates only; it never joins or writes another bounded context.
DO $role$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_fulfillment') THEN
        CREATE ROLE autoflow_fulfillment LOGIN PASSWORD 'local-only-fulfillment-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_fulfillment_publisher') THEN
        CREATE ROLE autoflow_fulfillment_publisher LOGIN PASSWORD 'local-only-fulfillment-publisher-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_fulfillment_outbox_owner') THEN
        CREATE ROLE autoflow_fulfillment_outbox_owner NOLOGIN
            NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;
    END IF;
END
$role$;

ALTER ROLE autoflow_fulfillment WITH LOGIN PASSWORD 'local-only-fulfillment-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
ALTER ROLE autoflow_fulfillment_publisher WITH LOGIN PASSWORD 'local-only-fulfillment-publisher-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
ALTER ROLE autoflow_fulfillment WITH LOGIN PASSWORD 'local-only-fulfillment-password'\n    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;\nALTER ROLE autoflow_fulfillment_publisher WITH LOGIN PASSWORD 'local-only-fulfillment-publisher-password'\n    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;\nALTER ROLE autoflow_fulfillment_outbox_owner NOLOGIN BYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_fulfillment, autoflow_fulfillment_publisher;
GRANT USAGE ON SCHEMA public TO autoflow_fulfillment, autoflow_fulfillment_publisher, autoflow_fulfillment_outbox_owner;

CREATE TABLE IF NOT EXISTS fulfillment_records (
    tenant_id VARCHAR(128) NOT NULL,
    fulfillment_id UUID NOT NULL,
    order_id UUID NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'awaiting_prerequisites'
        CHECK (status IN ('awaiting_prerequisites','ready_for_preparation','preparing','ready_for_dispatch','scheduled','in_transit','delivered','delivery_failed','cancelled')),
    payment_captured_at TIMESTAMPTZ NULL,
    contract_signed_at TIMESTAMPTZ NULL,
    scheduled_for TIMESTAMPTZ NULL,
    carrier VARCHAR(120) NULL,
    tracking_reference VARCHAR(180) NULL,
    dispatched_at TIMESTAMPTZ NULL,
    delivered_at TIMESTAMPTZ NULL,
    recipient_name VARCHAR(180) NULL,
    delivery_failure_reason VARCHAR(500) NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, fulfillment_id),
    UNIQUE (tenant_id, order_id),
    CHECK (status IN ('awaiting_prerequisites','cancelled') OR (payment_captured_at IS NOT NULL AND contract_signed_at IS NOT NULL)),
    CHECK (status <> 'delivered' OR (delivered_at IS NOT NULL AND recipient_name IS NOT NULL)),
    CHECK (status <> 'in_transit' OR (dispatched_at IS NOT NULL AND scheduled_for IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS idx_fulfillment_tenant_status_created
    ON fulfillment_records (tenant_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_fulfillment_scheduled
    ON fulfillment_records (scheduled_for)
    WHERE status = 'scheduled';

CREATE TABLE IF NOT EXISTS fulfillment_event_inbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    order_id UUID NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMPTZ NULL,
    PRIMARY KEY (tenant_id, event_id)
);
CREATE INDEX IF NOT EXISTS idx_fulfillment_inbox_unprocessed
    ON fulfillment_event_inbox (received_at)
    WHERE processed_at IS NULL;

CREATE TABLE IF NOT EXISTS fulfillment_audit_events (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    fulfillment_id UUID NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, event_id),
    FOREIGN KEY (tenant_id, fulfillment_id)
        REFERENCES fulfillment_records (tenant_id, fulfillment_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_fulfillment_audit_history
    ON fulfillment_audit_events (tenant_id, fulfillment_id, occurred_at DESC);

CREATE TABLE IF NOT EXISTS fulfillment_idempotency_keys (
    tenant_id VARCHAR(128) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    fulfillment_id UUID NOT NULL,
    response_body JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor_id, idempotency_key),
    FOREIGN KEY (tenant_id, fulfillment_id)
        REFERENCES fulfillment_records (tenant_id, fulfillment_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS fulfillment_outbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    fulfillment_id UUID NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMPTZ NULL,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMPTZ NULL,
    failed_at TIMESTAMPTZ NULL,
    last_error VARCHAR(500) NULL,
    PRIMARY KEY (tenant_id, event_id),
    FOREIGN KEY (tenant_id, fulfillment_id)
        REFERENCES fulfillment_records (tenant_id, fulfillment_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_fulfillment_outbox_pending
    ON fulfillment_outbox (next_attempt_at, occurred_at)
    WHERE published_at IS NULL AND failed_at IS NULL;

DO $rls$
DECLARE table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'fulfillment_records',
        'fulfillment_event_inbox',
        'fulfillment_audit_events',
        'fulfillment_idempotency_keys',
        'fulfillment_outbox'
    ] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('DROP POLICY IF EXISTS %I ON %I', table_name || '_tenant_isolation', table_name);
        EXECUTE format(
            'CREATE POLICY %I ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)) WITH CHECK (tenant_id = current_setting(''app.tenant_id'', true))',
            table_name || '_tenant_isolation', table_name
        );
    END LOOP;
END
$rls$;

GRANT SELECT, INSERT, UPDATE ON fulfillment_records TO autoflow_fulfillment;
GRANT SELECT, INSERT, UPDATE ON fulfillment_event_inbox TO autoflow_fulfillment;
GRANT SELECT, INSERT ON fulfillment_audit_events, fulfillment_idempotency_keys, fulfillment_outbox TO autoflow_fulfillment;

-- Outbox publishing is isolated behind narrowly scoped SECURITY DEFINER functions.
GRANT SELECT, UPDATE ON fulfillment_outbox TO autoflow_fulfillment_outbox_owner;

CREATE OR REPLACE FUNCTION public.fulfillment_outbox_claim()
RETURNS TABLE (tenant_id VARCHAR(128), event_id UUID, event_type VARCHAR(100), payload JSONB, attempts INTEGER)
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    WITH candidate AS (
        SELECT o.tenant_id, o.event_id
        FROM public.fulfillment_outbox o
        WHERE o.published_at IS NULL
          AND o.failed_at IS NULL
          AND o.next_attempt_at <= CURRENT_TIMESTAMP
          AND (o.locked_until IS NULL OR o.locked_until < CURRENT_TIMESTAMP)
        ORDER BY o.occurred_at
        FOR UPDATE SKIP LOCKED
        LIMIT 1
    )
    UPDATE public.fulfillment_outbox o
    SET locked_until = CURRENT_TIMESTAMP + INTERVAL '30 seconds',
        attempts = o.attempts + 1
    FROM candidate c
    WHERE o.tenant_id = c.tenant_id AND o.event_id = c.event_id
    RETURNING o.tenant_id, o.event_id, o.event_type, o.payload, o.attempts
$function$;

CREATE OR REPLACE FUNCTION public.fulfillment_outbox_mark_published(p_tenant_id VARCHAR(128), p_event_id UUID)
RETURNS VOID
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.fulfillment_outbox
    SET published_at = CURRENT_TIMESTAMP, locked_until = NULL, last_error = NULL
    WHERE tenant_id = p_tenant_id AND event_id = p_event_id
$function$;

CREATE OR REPLACE FUNCTION public.fulfillment_outbox_schedule_retry(
    p_tenant_id VARCHAR(128), p_event_id UUID, p_error VARCHAR(500)
)
RETURNS VOID
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.fulfillment_outbox
    SET locked_until = NULL,
        failed_at = CASE WHEN attempts >= 10 THEN CURRENT_TIMESTAMP ELSE NULL END,
        next_attempt_at = CASE
            WHEN attempts >= 10 THEN next_attempt_at
            ELSE CURRENT_TIMESTAMP + make_interval(secs => LEAST(300, POWER(2, LEAST(attempts, 8))::integer))
        END,
        last_error = p_error
    WHERE tenant_id = p_tenant_id AND event_id = p_event_id
$function$;

ALTER FUNCTION public.fulfillment_outbox_claim() OWNER TO autoflow_fulfillment_outbox_owner;
ALTER FUNCTION public.fulfillment_outbox_mark_published(VARCHAR, UUID) OWNER TO autoflow_fulfillment_outbox_owner;
ALTER FUNCTION public.fulfillment_outbox_schedule_retry(VARCHAR, UUID, VARCHAR) OWNER TO autoflow_fulfillment_outbox_owner;
REVOKE ALL ON FUNCTION public.fulfillment_outbox_claim() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.fulfillment_outbox_mark_published(VARCHAR, UUID) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.fulfillment_outbox_schedule_retry(VARCHAR, UUID, VARCHAR) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.fulfillment_outbox_claim() TO autoflow_fulfillment_publisher;
GRANT EXECUTE ON FUNCTION public.fulfillment_outbox_mark_published(VARCHAR, UUID) TO autoflow_fulfillment_publisher;
GRANT EXECUTE ON FUNCTION public.fulfillment_outbox_schedule_retry(VARCHAR, UUID, VARCHAR) TO autoflow_fulfillment_publisher;
