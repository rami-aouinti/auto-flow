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
