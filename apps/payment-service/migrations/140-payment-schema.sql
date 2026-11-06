CREATE TABLE IF NOT EXISTS payments (
    tenant_id VARCHAR(128) NOT NULL CHECK (tenant_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    payment_id UUID NOT NULL,
    order_id UUID NOT NULL,
    created_by VARCHAR(255) NOT NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'stripe',
    provider_payment_id VARCHAR(128) NULL,
    amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
    currency CHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    status VARCHAR(32) NOT NULL CHECK (status IN ('pending','requires_action','authorized','captured','failed','cancelled','partially_refunded','refunded')),
    amount_refunded_minor BIGINT NOT NULL DEFAULT 0 CHECK (amount_refunded_minor >= 0 AND amount_refunded_minor <= amount_minor),
    client_secret TEXT NULL,
    failure_code VARCHAR(100) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, payment_id),
    UNIQUE (provider, provider_payment_id)
);
CREATE INDEX IF NOT EXISTS idx_payments_tenant_order ON payments (tenant_id, order_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payments_tenant_status ON payments (tenant_id, status, created_at DESC);

CREATE TABLE IF NOT EXISTS payment_idempotency (
    tenant_id VARCHAR(128) NOT NULL,
    actor VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    payment_id UUID NOT NULL,
    response_json JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor, idempotency_key),
    FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, payment_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS payment_operation_idempotency (
    tenant_id VARCHAR(128) NOT NULL,
    actor VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    payment_id UUID NOT NULL,
    response_json JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor, idempotency_key),
    FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, payment_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS payment_refunds (
    tenant_id VARCHAR(128) NOT NULL,
    refund_id UUID NOT NULL,
    payment_id UUID NOT NULL,
    provider_refund_id VARCHAR(128) NULL,
    amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
    status VARCHAR(24) NOT NULL CHECK (status IN ('pending','succeeded','failed')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, refund_id),
    FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, payment_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    provider_event_id VARCHAR(128) PRIMARY KEY,
    tenant_id VARCHAR(128) NULL,
    event_type VARCHAR(128) NOT NULL,
    processed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payment_outbox (
    event_id UUID PRIMARY KEY,
    tenant_id VARCHAR(128) NOT NULL,
    payment_id UUID NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMPTZ NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMPTZ NULL,
    failed_at TIMESTAMPTZ NULL,
    last_error VARCHAR(500) NULL,
    FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, payment_id) ON DELETE RESTRICT
);

DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['payments','payment_idempotency','payment_operation_idempotency','payment_refunds','payment_outbox'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format('DROP POLICY IF EXISTS %I ON %I', t || '_tenant_isolation', t);
        EXECUTE format('CREATE POLICY %I ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)) WITH CHECK (tenant_id = current_setting(''app.tenant_id'', true))', t || '_tenant_isolation', t);
    END LOOP;
END
$$;

GRANT SELECT, INSERT, UPDATE ON payments TO autoflow_payment;
GRANT SELECT, INSERT ON payment_idempotency, payment_operation_idempotency, payment_refunds, payment_outbox TO autoflow_payment;
GRANT SELECT, INSERT ON payment_webhook_events TO autoflow_payment;

ALTER TABLE payment_outbox ADD COLUMN IF NOT EXISTS next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE payment_outbox ADD COLUMN IF NOT EXISTS locked_until TIMESTAMPTZ NULL;
ALTER TABLE payment_outbox ADD COLUMN IF NOT EXISTS failed_at TIMESTAMPTZ NULL;
ALTER TABLE payment_outbox ADD COLUMN IF NOT EXISTS last_error VARCHAR(500) NULL;
CREATE INDEX IF NOT EXISTS idx_payment_outbox_pending
    ON payment_outbox (next_attempt_at, occurred_at)
    WHERE published_at IS NULL AND failed_at IS NULL;

CREATE OR REPLACE FUNCTION public.payment_outbox_claim()
RETURNS TABLE (event_id UUID, event_type VARCHAR(100), payload JSONB, attempts INTEGER)
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    WITH candidate AS (
        SELECT o.event_id FROM public.payment_outbox o
        WHERE o.published_at IS NULL AND o.failed_at IS NULL
          AND o.next_attempt_at <= CURRENT_TIMESTAMP
          AND (o.locked_until IS NULL OR o.locked_until < CURRENT_TIMESTAMP)
        ORDER BY o.occurred_at FOR UPDATE SKIP LOCKED LIMIT 1
    )
    UPDATE public.payment_outbox o
    SET locked_until = CURRENT_TIMESTAMP + INTERVAL '30 seconds',
        attempts = o.attempts + 1
    FROM candidate c
    WHERE o.event_id = c.event_id
    RETURNING o.event_id, o.event_type, o.payload, o.attempts
$function$;

CREATE OR REPLACE FUNCTION public.payment_outbox_mark_published(p_event_id UUID)
RETURNS VOID LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.payment_outbox
    SET published_at = CURRENT_TIMESTAMP, locked_until = NULL, last_error = NULL
    WHERE event_id = p_event_id
$function$;

CREATE OR REPLACE FUNCTION public.payment_outbox_schedule_retry(p_event_id UUID, p_error VARCHAR(500))
RETURNS VOID LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.payment_outbox
    SET locked_until = NULL,
        failed_at = CASE WHEN attempts >= 10 THEN CURRENT_TIMESTAMP ELSE NULL END,
        next_attempt_at = CASE
            WHEN attempts >= 10 THEN next_attempt_at
            ELSE CURRENT_TIMESTAMP + make_interval(secs => LEAST(300, POWER(2, LEAST(attempts, 8))::integer))
        END,
        last_error = p_error
    WHERE event_id = p_event_id
$function$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_payment_outbox_owner') THEN
        CREATE ROLE autoflow_payment_outbox_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;
    END IF;
END
$$;
ALTER ROLE autoflow_payment_outbox_owner NOLOGIN BYPASSRLS;
GRANT USAGE, CREATE ON SCHEMA public TO autoflow_payment_outbox_owner;
GRANT SELECT, UPDATE ON payment_outbox TO autoflow_payment_outbox_owner;
ALTER FUNCTION public.payment_outbox_claim() OWNER TO autoflow_payment_outbox_owner;
ALTER FUNCTION public.payment_outbox_mark_published(UUID) OWNER TO autoflow_payment_outbox_owner;
ALTER FUNCTION public.payment_outbox_schedule_retry(UUID, VARCHAR) OWNER TO autoflow_payment_outbox_owner;
REVOKE ALL ON FUNCTION public.payment_outbox_claim() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.payment_outbox_mark_published(UUID) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.payment_outbox_schedule_retry(UUID, VARCHAR) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.payment_outbox_claim() TO autoflow_payment_publisher;
GRANT EXECUTE ON FUNCTION public.payment_outbox_mark_published(UUID) TO autoflow_payment_publisher;
GRANT EXECUTE ON FUNCTION public.payment_outbox_schedule_retry(UUID, VARCHAR) TO autoflow_payment_publisher;
