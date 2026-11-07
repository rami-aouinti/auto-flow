DO $role$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_contract') THEN
        CREATE ROLE autoflow_contract LOGIN PASSWORD 'local-only-contract-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$role$;

ALTER ROLE autoflow_contract WITH LOGIN PASSWORD 'local-only-contract-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_contract;
GRANT USAGE ON SCHEMA public TO autoflow_contract;

CREATE TABLE IF NOT EXISTS contract_records (
    tenant_id VARCHAR(128) NOT NULL,
    contract_id UUID NOT NULL,
    order_id UUID NOT NULL,
    customer_id UUID NOT NULL,
    contract_type VARCHAR(40) NOT NULL CHECK (contract_type IN ('vehicle_purchase', 'vehicle_lease', 'vehicle_subscription')),
    contract_version INTEGER NOT NULL CHECK (contract_version > 0),
    status VARCHAR(24) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'prepared', 'awaiting_signature', 'signed', 'cancelled', 'superseded')),
    legal_snapshot JSONB NOT NULL,
    document_checksum CHAR(64) NULL,
    signature_provider VARCHAR(80) NULL,
    provider_envelope_id VARCHAR(180) NULL,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    prepared_at TIMESTAMPTZ NULL,
    signed_at TIMESTAMPTZ NULL,
    cancelled_at TIMESTAMPTZ NULL,
    PRIMARY KEY (tenant_id, contract_id),
    UNIQUE (tenant_id, order_id, contract_type, contract_version),
    CHECK (jsonb_typeof(legal_snapshot) = 'object'),
    CHECK ((status <> 'signed') OR signed_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_contract_records_tenant_status_created
    ON contract_records (tenant_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_contract_records_tenant_order
    ON contract_records (tenant_id, order_id, created_at DESC);

CREATE TABLE IF NOT EXISTS contract_audit_events (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    contract_id UUID NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, event_id),
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_contract_audit_history
    ON contract_audit_events (tenant_id, contract_id, occurred_at DESC);

CREATE TABLE IF NOT EXISTS contract_idempotency_keys (
    tenant_id VARCHAR(128) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    contract_id UUID NOT NULL,
    response_body JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor_id, idempotency_key),
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS contract_transition_idempotency (
    tenant_id VARCHAR(128) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    contract_id UUID NOT NULL,
    response_body JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor_id, idempotency_key),
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS contract_documents (
    tenant_id VARCHAR(128) NOT NULL,
    document_id UUID NOT NULL,
    contract_id UUID NOT NULL,
    document_version INTEGER NOT NULL CHECK (document_version > 0),
    storage_key VARCHAR(512) NOT NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    checksum_sha256 CHAR(64) NOT NULL CHECK (checksum_sha256 ~ '^[a-f0-9]{64}
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    contract_id UUID NOT NULL,
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
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_contract_outbox_pending
    ON contract_outbox (next_attempt_at, occurred_at)
    WHERE published_at IS NULL AND failed_at IS NULL;

DO $rls$
DECLARE table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'contract_records', 'contract_audit_events', 'contract_idempotency_keys', 'contract_transition_idempotency', 'contract_documents', 'contract_signature_webhook_inbox', 'contract_outbox'
    ] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('DROP POLICY IF EXISTS %I ON %I', table_name || '_tenant_isolation', table_name);
        EXECUTE format(
            'CREATE POLICY %I ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)) WITH CHECK (tenant_id = current_setting(''app.tenant_id'', true))',
            table_name || '_tenant_isolation',
            table_name
        );
    END LOOP;
END
$rls$;

GRANT SELECT, INSERT ON contract_records TO autoflow_contract;
GRANT UPDATE (
    status, updated_at, prepared_at, signed_at, cancelled_at,
    document_checksum, signature_provider, provider_envelope_id
) ON contract_records TO autoflow_contract;
GRANT SELECT, INSERT ON contract_audit_events, contract_idempotency_keys, contract_transition_idempotency, contract_documents, contract_signature_webhook_inbox, contract_outbox TO autoflow_contract;


-- The publisher has no direct table access. SECURITY DEFINER functions run under
-- a narrowly scoped owner role and use a fixed search_path.
CREATE OR REPLACE FUNCTION public.contract_outbox_claim()
RETURNS TABLE (event_id UUID, event_type VARCHAR(100), payload JSONB, attempts INTEGER)
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    WITH candidate AS (
        SELECT o.event_id
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
    WHERE o.event_id = c.event_id
    RETURNING o.event_id, o.event_type, o.payload, o.attempts
$function$;

CREATE OR REPLACE FUNCTION public.contract_outbox_mark_published(p_event_id UUID)
RETURNS VOID
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.contract_outbox
    SET published_at = CURRENT_TIMESTAMP,
        locked_until = NULL,
        last_error = NULL
    WHERE event_id = p_event_id
$function$;

CREATE OR REPLACE FUNCTION public.contract_outbox_schedule_retry(
    p_event_id UUID,
    p_error VARCHAR(500)
)
RETURNS VOID
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.contract_outbox
    SET locked_until = NULL,
        failed_at = CASE WHEN attempts >= 10 THEN CURRENT_TIMESTAMP ELSE NULL END,
        next_attempt_at = CASE
            WHEN attempts >= 10 THEN next_attempt_at
            ELSE CURRENT_TIMESTAMP
                + make_interval(secs => LEAST(300, POWER(2, LEAST(attempts, 8))::integer))
        END,
        last_error = p_error
    WHERE event_id = p_event_id
$function$;

DO $publisher_role$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_contract_outbox_owner') THEN
        CREATE ROLE autoflow_contract_outbox_owner
            NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_contract_publisher') THEN
        CREATE ROLE autoflow_contract_publisher
            LOGIN PASSWORD 'local-only-contract-publisher-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$publisher_role$;

ALTER ROLE autoflow_contract_outbox_owner NOLOGIN BYPASSRLS;
ALTER ROLE autoflow_contract_publisher
    LOGIN PASSWORD 'local-only-contract-publisher-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_contract_publisher;
GRANT USAGE ON SCHEMA public TO autoflow_contract_outbox_owner, autoflow_contract_publisher;
GRANT SELECT, UPDATE ON public.contract_outbox TO autoflow_contract_outbox_owner;
ALTER FUNCTION public.contract_outbox_claim() OWNER TO autoflow_contract_outbox_owner;
ALTER FUNCTION public.contract_outbox_mark_published(UUID) OWNER TO autoflow_contract_outbox_owner;
ALTER FUNCTION public.contract_outbox_schedule_retry(UUID, VARCHAR) OWNER TO autoflow_contract_outbox_owner;
REVOKE ALL ON FUNCTION public.contract_outbox_claim() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.contract_outbox_mark_published(UUID) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.contract_outbox_schedule_retry(UUID, VARCHAR) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.contract_outbox_claim() TO autoflow_contract_publisher;
GRANT EXECUTE ON FUNCTION public.contract_outbox_mark_published(UUID) TO autoflow_contract_publisher;
GRANT EXECUTE ON FUNCTION public.contract_outbox_schedule_retry(UUID, VARCHAR) TO autoflow_contract_publisher;
),
    byte_size BIGINT NOT NULL CHECK (byte_size > 0),
    generated_by VARCHAR(255) NOT NULL,
    generated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, document_id),
    UNIQUE (tenant_id, contract_id, document_version),
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_contract_documents_latest
    ON contract_documents (tenant_id, contract_id, document_version DESC);

CREATE TABLE IF NOT EXISTS contract_signature_webhook_inbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_key CHAR(64) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    provider_envelope_id VARCHAR(180) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMPTZ NULL,
    PRIMARY KEY (tenant_id, event_key)
);

CREATE TABLE IF NOT EXISTS contract_outbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    contract_id UUID NOT NULL,
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
    FOREIGN KEY (tenant_id, contract_id)
        REFERENCES contract_records (tenant_id, contract_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_contract_outbox_pending
    ON contract_outbox (next_attempt_at, occurred_at)
    WHERE published_at IS NULL AND failed_at IS NULL;

DO $rls$
DECLARE table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'contract_records', 'contract_audit_events', 'contract_idempotency_keys', 'contract_transition_idempotency', 'contract_outbox'
    ] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('DROP POLICY IF EXISTS %I ON %I', table_name || '_tenant_isolation', table_name);
        EXECUTE format(
            'CREATE POLICY %I ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)) WITH CHECK (tenant_id = current_setting(''app.tenant_id'', true))',
            table_name || '_tenant_isolation',
            table_name
        );
    END LOOP;
END
$rls$;

GRANT SELECT, INSERT ON contract_records TO autoflow_contract;
GRANT UPDATE (
    status, updated_at, prepared_at, signed_at, cancelled_at,
    document_checksum, signature_provider, provider_envelope_id
) ON contract_records TO autoflow_contract;
GRANT SELECT, INSERT ON contract_audit_events, contract_idempotency_keys, contract_transition_idempotency, contract_outbox TO autoflow_contract;


-- The publisher has no direct table access. SECURITY DEFINER functions run under
-- a narrowly scoped owner role and use a fixed search_path.
CREATE OR REPLACE FUNCTION public.contract_outbox_claim()
RETURNS TABLE (event_id UUID, event_type VARCHAR(100), payload JSONB, attempts INTEGER)
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    WITH candidate AS (
        SELECT o.event_id
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
    WHERE o.event_id = c.event_id
    RETURNING o.event_id, o.event_type, o.payload, o.attempts
$function$;

CREATE OR REPLACE FUNCTION public.contract_outbox_mark_published(p_event_id UUID)
RETURNS VOID
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.contract_outbox
    SET published_at = CURRENT_TIMESTAMP,
        locked_until = NULL,
        last_error = NULL
    WHERE event_id = p_event_id
$function$;

CREATE OR REPLACE FUNCTION public.contract_outbox_schedule_retry(
    p_event_id UUID,
    p_error VARCHAR(500)
)
RETURNS VOID
LANGUAGE sql SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.contract_outbox
    SET locked_until = NULL,
        failed_at = CASE WHEN attempts >= 10 THEN CURRENT_TIMESTAMP ELSE NULL END,
        next_attempt_at = CASE
            WHEN attempts >= 10 THEN next_attempt_at
            ELSE CURRENT_TIMESTAMP
                + make_interval(secs => LEAST(300, POWER(2, LEAST(attempts, 8))::integer))
        END,
        last_error = p_error
    WHERE event_id = p_event_id
$function$;

DO $publisher_role$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_contract_outbox_owner') THEN
        CREATE ROLE autoflow_contract_outbox_owner
            NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_contract_publisher') THEN
        CREATE ROLE autoflow_contract_publisher
            LOGIN PASSWORD 'local-only-contract-publisher-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$publisher_role$;

ALTER ROLE autoflow_contract_outbox_owner NOLOGIN BYPASSRLS;
ALTER ROLE autoflow_contract_publisher
    LOGIN PASSWORD 'local-only-contract-publisher-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_contract_publisher;
GRANT USAGE ON SCHEMA public TO autoflow_contract_outbox_owner, autoflow_contract_publisher;
GRANT SELECT, UPDATE ON public.contract_outbox TO autoflow_contract_outbox_owner;
ALTER FUNCTION public.contract_outbox_claim() OWNER TO autoflow_contract_outbox_owner;
ALTER FUNCTION public.contract_outbox_mark_published(UUID) OWNER TO autoflow_contract_outbox_owner;
ALTER FUNCTION public.contract_outbox_schedule_retry(UUID, VARCHAR) OWNER TO autoflow_contract_outbox_owner;
REVOKE ALL ON FUNCTION public.contract_outbox_claim() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.contract_outbox_mark_published(UUID) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.contract_outbox_schedule_retry(UUID, VARCHAR) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.contract_outbox_claim() TO autoflow_contract_publisher;
GRANT EXECUTE ON FUNCTION public.contract_outbox_mark_published(UUID) TO autoflow_contract_publisher;
GRANT EXECUTE ON FUNCTION public.contract_outbox_schedule_retry(UUID, VARCHAR) TO autoflow_contract_publisher;
