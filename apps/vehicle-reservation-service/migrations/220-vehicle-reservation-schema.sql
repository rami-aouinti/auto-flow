-- Vehicle Reservation owns allocation of physical stock units.
-- Catalog variant IDs describe a model/configuration; vehicle_unit_id identifies
-- one allocatable physical unit and is globally unique while actively reserved.
DO $roles$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_reservation') THEN
        CREATE ROLE autoflow_reservation LOGIN PASSWORD 'local-only-reservation-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$roles$;

ALTER ROLE autoflow_reservation WITH LOGIN PASSWORD 'local-only-reservation-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_reservation;
GRANT USAGE ON SCHEMA public TO autoflow_reservation;

CREATE TABLE IF NOT EXISTS vehicle_reservations (
    tenant_id VARCHAR(128) NOT NULL,
    reservation_id UUID NOT NULL,
    vehicle_unit_id UUID NOT NULL,
    vehicle_variant_id UUID NOT NULL,
    order_id UUID NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'held'
        CHECK (status IN ('held', 'confirmed', 'expired', 'released', 'fulfilled')),
    expires_at TIMESTAMPTZ NOT NULL,
    confirmed_at TIMESTAMPTZ NULL,
    released_at TIMESTAMPTZ NULL,
    fulfilled_at TIMESTAMPTZ NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, reservation_id),
    UNIQUE (tenant_id, order_id),
    CHECK (status <> 'confirmed' OR confirmed_at IS NOT NULL),
    CHECK (status <> 'released' OR released_at IS NOT NULL),
    CHECK (status <> 'fulfilled' OR fulfilled_at IS NOT NULL)
);

-- A physical unit may have only one live hold/confirmation across all tenants.
CREATE UNIQUE INDEX IF NOT EXISTS uq_vehicle_reservations_active_unit
    ON vehicle_reservations (vehicle_unit_id)
    WHERE status IN ('held', 'confirmed');
CREATE INDEX IF NOT EXISTS idx_vehicle_reservations_tenant_status
    ON vehicle_reservations (tenant_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_vehicle_reservations_expiry
    ON vehicle_reservations (expires_at)
    WHERE status = 'held';

CREATE TABLE IF NOT EXISTS vehicle_reservation_event_inbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMPTZ NULL,
    PRIMARY KEY (tenant_id, event_id)
);

CREATE TABLE IF NOT EXISTS vehicle_reservation_audit_events (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    reservation_id UUID NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, event_id),
    FOREIGN KEY (tenant_id, reservation_id)
        REFERENCES vehicle_reservations (tenant_id, reservation_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_vehicle_reservation_audit_history
    ON vehicle_reservation_audit_events (tenant_id, reservation_id, occurred_at DESC);

CREATE TABLE IF NOT EXISTS vehicle_reservation_idempotency (
    tenant_id VARCHAR(128) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    reservation_id UUID NOT NULL,
    response_body JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, actor_id, idempotency_key),
    FOREIGN KEY (tenant_id, reservation_id)
        REFERENCES vehicle_reservations (tenant_id, reservation_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS vehicle_reservation_outbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    reservation_id UUID NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMPTZ NULL,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMPTZ NULL,
    failed_at TIMESTAMPTZ NULL,
    last_error VARCHAR(500) NULL,
    PRIMARY KEY (tenant_id, event_id),
    FOREIGN KEY (tenant_id, reservation_id)
        REFERENCES vehicle_reservations (tenant_id, reservation_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_vehicle_reservation_outbox_pending
    ON vehicle_reservation_outbox (next_attempt_at, occurred_at)
    WHERE published_at IS NULL AND failed_at IS NULL;

DO $rls$
DECLARE table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'vehicle_reservations',
        'vehicle_reservation_event_inbox',
        'vehicle_reservation_audit_events',
        'vehicle_reservation_idempotency',
        'vehicle_reservation_outbox'
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

GRANT SELECT, INSERT, UPDATE ON vehicle_reservations TO autoflow_reservation;
GRANT SELECT, INSERT, UPDATE ON vehicle_reservation_event_inbox TO autoflow_reservation;
GRANT SELECT, INSERT ON vehicle_reservation_audit_events, vehicle_reservation_idempotency, vehicle_reservation_outbox TO autoflow_reservation;


-- Dedicated publisher and expiry-worker identities. The worker owns only
-- SECURITY DEFINER functions and the minimal table privileges those functions need.
DO $roles$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_reservation_publisher') THEN
        CREATE ROLE autoflow_reservation_publisher LOGIN PASSWORD 'local-only-reservation-publisher-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_reservation_worker_owner') THEN
        CREATE ROLE autoflow_reservation_worker_owner NOLOGIN
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT BYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_reservation_worker') THEN
        CREATE ROLE autoflow_reservation_worker LOGIN PASSWORD 'local-only-reservation-worker-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$roles$;

ALTER ROLE autoflow_reservation_publisher WITH LOGIN PASSWORD 'local-only-reservation-publisher-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
ALTER ROLE autoflow_reservation_worker WITH LOGIN PASSWORD 'local-only-reservation-worker-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
ALTER ROLE autoflow_reservation_worker_owner NOLOGIN
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT BYPASSRLS;

GRANT CONNECT ON DATABASE autoflow TO autoflow_reservation_publisher, autoflow_reservation_worker;
GRANT USAGE ON SCHEMA public TO autoflow_reservation_publisher, autoflow_reservation_worker, autoflow_reservation_worker_owner;

GRANT SELECT, UPDATE ON vehicle_reservation_outbox TO autoflow_reservation_worker_owner;
GRANT SELECT, UPDATE ON vehicle_reservations TO autoflow_reservation_worker_owner;
GRANT INSERT ON vehicle_reservation_audit_events TO autoflow_reservation_worker_owner;

CREATE OR REPLACE FUNCTION public.vehicle_reservation_expire_due(p_limit INTEGER DEFAULT 100)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
DECLARE
    reservation RECORD;
    event_id UUID;
    event_payload JSONB;
    now_at TIMESTAMPTZ := clock_timestamp();
    processed INTEGER := 0;
BEGIN
    FOR reservation IN
        SELECT tenant_id, reservation_id, vehicle_unit_id, vehicle_variant_id, order_id, version
        FROM public.vehicle_reservations
        WHERE status = 'held' AND expires_at <= now_at
        ORDER BY expires_at
        FOR UPDATE SKIP LOCKED
        LIMIT GREATEST(1, LEAST(p_limit, 500))
    LOOP
        UPDATE public.vehicle_reservations
        SET status = 'expired', version = version + 1, updated_at = now_at
        WHERE tenant_id = reservation.tenant_id
          AND reservation_id = reservation.reservation_id
          AND status = 'held';

        IF NOT FOUND THEN
            CONTINUE;
        END IF;

        event_id := gen_random_uuid();
        event_payload := jsonb_build_object(
            'eventId', event_id,
            'eventType', 'reservation.expired.v1',
            'schemaVersion', 1,
            'producer', 'vehicle-reservation-service',
            'aggregate', jsonb_build_object('type', 'VehicleReservation', 'id', reservation.reservation_id),
            'tenantId', reservation.tenant_id,
            'occurredAt', now_at,
            'data', jsonb_build_object(
                'reservationId', reservation.reservation_id,
                'vehicleUnitId', reservation.vehicle_unit_id,
                'vehicleVariantId', reservation.vehicle_variant_id,
                'orderId', reservation.order_id,
                'previousStatus', 'held',
                'status', 'expired',
                'version', reservation.version + 1
            )
        );

        INSERT INTO public.vehicle_reservation_audit_events
            (tenant_id, event_id, reservation_id, actor_id, event_type, payload, occurred_at)
        VALUES
            (reservation.tenant_id, event_id, reservation.reservation_id, 'system:reservation-expirer',
             'ReservationExpired', event_payload, now_at);

        INSERT INTO public.vehicle_reservation_outbox
            (tenant_id, event_id, reservation_id, event_type, payload, occurred_at)
        VALUES
            (reservation.tenant_id, event_id, reservation.reservation_id, 'reservation.expired.v1', event_payload, now_at);

        processed := processed + 1;
    END LOOP;

    RETURN processed;
END
$function$;

CREATE OR REPLACE FUNCTION public.vehicle_reservation_outbox_claim()
RETURNS TABLE (tenant_id VARCHAR(128), event_id UUID, event_type VARCHAR(120), payload JSONB, attempts INTEGER)
LANGUAGE sql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    WITH candidate AS (
        SELECT o.tenant_id, o.event_id
        FROM public.vehicle_reservation_outbox o
        WHERE o.published_at IS NULL
          AND o.failed_at IS NULL
          AND o.next_attempt_at <= CURRENT_TIMESTAMP
          AND (o.locked_until IS NULL OR o.locked_until < CURRENT_TIMESTAMP)
        ORDER BY o.occurred_at
        FOR UPDATE SKIP LOCKED
        LIMIT 1
    )
    UPDATE public.vehicle_reservation_outbox o
    SET locked_until = CURRENT_TIMESTAMP + INTERVAL '30 seconds',
        attempts = o.attempts + 1
    FROM candidate c
    WHERE o.tenant_id = c.tenant_id AND o.event_id = c.event_id
    RETURNING o.tenant_id, o.event_id, o.event_type, o.payload, o.attempts
$function$;

CREATE OR REPLACE FUNCTION public.vehicle_reservation_outbox_mark_published(p_tenant_id VARCHAR(128), p_event_id UUID)
RETURNS VOID
LANGUAGE sql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.vehicle_reservation_outbox
    SET published_at = CURRENT_TIMESTAMP, locked_until = NULL, last_error = NULL
    WHERE tenant_id = p_tenant_id AND event_id = p_event_id
$function$;

CREATE OR REPLACE FUNCTION public.vehicle_reservation_outbox_schedule_retry(
    p_tenant_id VARCHAR(128), p_event_id UUID, p_error VARCHAR(500)
)
RETURNS VOID
LANGUAGE sql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
    UPDATE public.vehicle_reservation_outbox
    SET locked_until = NULL,
        failed_at = CASE WHEN attempts >= 12 THEN CURRENT_TIMESTAMP ELSE NULL END,
        next_attempt_at = CURRENT_TIMESTAMP + make_interval(secs => LEAST(300, POWER(2, LEAST(attempts, 8))::INTEGER)),
        last_error = p_error
    WHERE tenant_id = p_tenant_id AND event_id = p_event_id
$function$;

ALTER FUNCTION public.vehicle_reservation_expire_due(INTEGER) OWNER TO autoflow_reservation_worker_owner;
ALTER FUNCTION public.vehicle_reservation_outbox_claim() OWNER TO autoflow_reservation_worker_owner;
ALTER FUNCTION public.vehicle_reservation_outbox_mark_published(VARCHAR, UUID) OWNER TO autoflow_reservation_worker_owner;
ALTER FUNCTION public.vehicle_reservation_outbox_schedule_retry(VARCHAR, UUID, VARCHAR) OWNER TO autoflow_reservation_worker_owner;

REVOKE ALL ON FUNCTION public.vehicle_reservation_expire_due(INTEGER) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.vehicle_reservation_outbox_claim() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.vehicle_reservation_outbox_mark_published(VARCHAR, UUID) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.vehicle_reservation_outbox_schedule_retry(VARCHAR, UUID, VARCHAR) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION public.vehicle_reservation_expire_due(INTEGER) TO autoflow_reservation_worker;
GRANT EXECUTE ON FUNCTION public.vehicle_reservation_outbox_claim() TO autoflow_reservation_publisher;
GRANT EXECUTE ON FUNCTION public.vehicle_reservation_outbox_mark_published(VARCHAR, UUID) TO autoflow_reservation_publisher;
GRANT EXECUTE ON FUNCTION public.vehicle_reservation_outbox_schedule_retry(VARCHAR, UUID, VARCHAR) TO autoflow_reservation_publisher;
