-- Physical stock registry owned by Vehicle Reservation.
-- A catalogue variant is not stock: every row below represents one real vehicle unit.
DO $roles$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_reservation_stock_owner') THEN
        CREATE ROLE autoflow_reservation_stock_owner NOLOGIN
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT BYPASSRLS;
    END IF;
END
$roles$;

ALTER ROLE autoflow_reservation_stock_owner NOLOGIN
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT BYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_reservation_stock_owner;
GRANT USAGE ON SCHEMA public TO autoflow_reservation_stock_owner;
GRANT CREATE ON SCHEMA public TO autoflow_reservation_stock_owner;

CREATE TABLE IF NOT EXISTS vehicle_stock_units (
    tenant_id VARCHAR(128) NOT NULL,
    vehicle_unit_id UUID NOT NULL,
    vehicle_variant_id UUID NOT NULL,
    vin VARCHAR(32) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available'
        CHECK (status IN ('available', 'reserved', 'unavailable', 'sold')),
    reservation_id UUID NULL,
    order_id UUID NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, vehicle_unit_id),
    UNIQUE (vehicle_unit_id),
    UNIQUE (vin),
    CHECK (
        (status = 'reserved' AND reservation_id IS NOT NULL AND order_id IS NOT NULL)
        OR (status <> 'reserved' AND reservation_id IS NULL AND order_id IS NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_vehicle_stock_available_variant
    ON vehicle_stock_units (tenant_id, vehicle_variant_id, created_at)
    WHERE status = 'available';

ALTER TABLE vehicle_stock_units ENABLE ROW LEVEL SECURITY;
ALTER TABLE vehicle_stock_units FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS vehicle_stock_units_tenant_isolation ON vehicle_stock_units;
CREATE POLICY vehicle_stock_units_tenant_isolation ON vehicle_stock_units
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));

GRANT SELECT ON vehicle_stock_units TO autoflow_reservation;
GRANT SELECT, INSERT, UPDATE ON vehicle_stock_units TO autoflow_reservation_stock_owner;
GRANT SELECT, INSERT, UPDATE ON vehicle_reservations TO autoflow_reservation_stock_owner;
GRANT INSERT ON vehicle_reservation_audit_events, vehicle_reservation_outbox TO autoflow_reservation_stock_owner;

CREATE OR REPLACE FUNCTION public.vehicle_stock_sync_reservation()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
BEGIN
    IF TG_OP = 'INSERT' THEN
        UPDATE public.vehicle_stock_units
        SET status = 'reserved',
            reservation_id = NEW.reservation_id,
            order_id = NEW.order_id,
            updated_at = CURRENT_TIMESTAMP
        WHERE vehicle_unit_id = NEW.vehicle_unit_id
          AND tenant_id = NEW.tenant_id
          AND status = 'available';
    ELSIF OLD.status IN ('held', 'confirmed') AND NEW.status IN ('expired', 'released') THEN
        UPDATE public.vehicle_stock_units
        SET status = 'available',
            reservation_id = NULL,
            order_id = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE vehicle_unit_id = OLD.vehicle_unit_id
          AND tenant_id = OLD.tenant_id
          AND reservation_id = OLD.reservation_id
          AND status = 'reserved';
    ELSIF OLD.status IN ('held', 'confirmed') AND NEW.status = 'fulfilled' THEN
        UPDATE public.vehicle_stock_units
        SET status = 'sold',
            reservation_id = NULL,
            order_id = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE vehicle_unit_id = OLD.vehicle_unit_id
          AND tenant_id = OLD.tenant_id
          AND reservation_id = OLD.reservation_id
          AND status = 'reserved';
    END IF;

    RETURN NEW;
END
$function$;

ALTER FUNCTION public.vehicle_stock_sync_reservation() OWNER TO autoflow_reservation_stock_owner;
DROP TRIGGER IF EXISTS trg_vehicle_stock_sync_reservation ON vehicle_reservations;
CREATE TRIGGER trg_vehicle_stock_sync_reservation
AFTER INSERT OR UPDATE OF status ON vehicle_reservations
FOR EACH ROW EXECUTE FUNCTION public.vehicle_stock_sync_reservation();

CREATE OR REPLACE FUNCTION public.vehicle_reservation_hold_available(
    p_tenant_id VARCHAR(128),
    p_vehicle_variant_id UUID,
    p_order_id UUID,
    p_expires_at TIMESTAMPTZ
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
DECLARE
    existing_reservation RECORD;
    selected_unit RECORD;
    new_reservation_id UUID;
    new_event_id UUID;
    now_at TIMESTAMPTZ := clock_timestamp();
    envelope JSONB;
    response JSONB;
BEGIN
    IF p_tenant_id IS NULL OR p_tenant_id !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' THEN
        RAISE EXCEPTION 'invalid tenant id' USING ERRCODE = '22023';
    END IF;
    IF p_expires_at <= now_at OR p_expires_at > now_at + INTERVAL '24 hours' THEN
        RAISE EXCEPTION 'reservation expiry must be within the next 24 hours' USING ERRCODE = '22023';
    END IF;

    SELECT r.reservation_id, r.vehicle_unit_id, r.vehicle_variant_id, r.order_id,
           r.status, r.expires_at, r.confirmed_at, r.released_at, r.fulfilled_at,
           r.version, r.created_at, r.updated_at
    INTO existing_reservation
    FROM public.vehicle_reservations r
    WHERE r.tenant_id = p_tenant_id AND r.order_id = p_order_id
    FOR UPDATE;

    IF FOUND THEN
        IF existing_reservation.vehicle_variant_id <> p_vehicle_variant_id THEN
            RAISE EXCEPTION 'order already has a reservation for a different vehicle variant'
                USING ERRCODE = '23505';
        END IF;
        IF existing_reservation.status IN ('held', 'confirmed') THEN
            RETURN jsonb_build_object(
                'id', existing_reservation.reservation_id,
                'tenantId', p_tenant_id,
                'vehicleUnitId', existing_reservation.vehicle_unit_id,
                'vehicleVariantId', existing_reservation.vehicle_variant_id,
                'orderId', p_order_id,
                'status', existing_reservation.status,
                'expiresAt', existing_reservation.expires_at,
                'confirmedAt', existing_reservation.confirmed_at,
                'releasedAt', existing_reservation.released_at,
                'fulfilledAt', existing_reservation.fulfilled_at,
                'version', existing_reservation.version,
                'createdAt', existing_reservation.created_at,
                'updatedAt', existing_reservation.updated_at,
                'replayed', true
            );
        END IF;
        RAISE EXCEPTION 'order already has a terminal reservation'
            USING ERRCODE = '23505';
    END IF;

    SELECT s.vehicle_unit_id, s.vehicle_variant_id
    INTO selected_unit
    FROM public.vehicle_stock_units s
    WHERE s.tenant_id = p_tenant_id
      AND s.vehicle_variant_id = p_vehicle_variant_id
      AND s.status = 'available'
    ORDER BY s.created_at, s.vehicle_unit_id
    FOR UPDATE SKIP LOCKED
    LIMIT 1;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'no available physical vehicle unit for this variant'
            USING ERRCODE = 'P0002';
    END IF;

    new_reservation_id := gen_random_uuid();
    new_event_id := gen_random_uuid();

    INSERT INTO public.vehicle_reservations (
        tenant_id, reservation_id, vehicle_unit_id, vehicle_variant_id,
        order_id, status, expires_at
    ) VALUES (
        p_tenant_id, new_reservation_id, selected_unit.vehicle_unit_id,
        selected_unit.vehicle_variant_id, p_order_id, 'held', p_expires_at
    );

    -- The trigger reserves the selected stock unit in the same transaction.
    IF NOT EXISTS (
        SELECT 1 FROM public.vehicle_stock_units
        WHERE tenant_id = p_tenant_id
          AND vehicle_unit_id = selected_unit.vehicle_unit_id
          AND status = 'reserved'
          AND reservation_id = new_reservation_id
    ) THEN
        RAISE EXCEPTION 'selected stock unit could not be allocated'
            USING ERRCODE = '40001';
    END IF;

    response := jsonb_build_object(
        'id', new_reservation_id,
        'tenantId', p_tenant_id,
        'vehicleUnitId', selected_unit.vehicle_unit_id,
        'vehicleVariantId', selected_unit.vehicle_variant_id,
        'orderId', p_order_id,
        'status', 'held',
        'expiresAt', p_expires_at,
        'confirmedAt', NULL,
        'releasedAt', NULL,
        'fulfilledAt', NULL,
        'version', 1,
        'createdAt', now_at,
        'updatedAt', now_at,
        'replayed', false
    );
    envelope := jsonb_build_object(
        'eventId', new_event_id,
        'eventType', 'reservation.held.v1',
        'schemaVersion', 1,
        'producer', 'vehicle-reservation-service',
        'aggregate', jsonb_build_object('type', 'VehicleReservation', 'id', new_reservation_id),
        'tenantId', p_tenant_id,
        'occurredAt', now_at,
        'data', response
    );

    INSERT INTO public.vehicle_reservation_audit_events (
        tenant_id, event_id, reservation_id, actor_id, event_type, payload, occurred_at
    ) VALUES (
        p_tenant_id, new_event_id, new_reservation_id, 'order-saga', 'ReservationHeld',
        envelope, now_at
    );
    INSERT INTO public.vehicle_reservation_outbox (
        tenant_id, event_id, reservation_id, event_type, payload, occurred_at
    ) VALUES (
        p_tenant_id, new_event_id, new_reservation_id, 'reservation.held.v1', envelope, now_at
    );

    RETURN response;
END
$function$;

ALTER FUNCTION public.vehicle_reservation_hold_available(VARCHAR, UUID, UUID, TIMESTAMPTZ)
    OWNER TO autoflow_reservation_stock_owner;

GRANT EXECUTE ON FUNCTION public.vehicle_reservation_hold_available(VARCHAR, UUID, UUID, TIMESTAMPTZ)
    TO autoflow_reservation_worker;

CREATE OR REPLACE FUNCTION public.vehicle_reservation_release_for_order(
    p_tenant_id VARCHAR(128),
    p_reservation_id UUID,
    p_order_id UUID
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
DECLARE
    reservation RECORD;
    event_id UUID;
    now_at TIMESTAMPTZ := clock_timestamp();
    response JSONB;
    envelope JSONB;
BEGIN
    SELECT * INTO reservation
    FROM public.vehicle_reservations
    WHERE tenant_id = p_tenant_id
      AND reservation_id = p_reservation_id
      AND order_id = p_order_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'reservation does not belong to the supplied tenant and order'
            USING ERRCODE = 'P0002';
    END IF;

    IF reservation.status = 'released' THEN
        RETURN jsonb_build_object(
            'reservationId', reservation.reservation_id,
            'orderId', reservation.order_id,
            'vehicleUnitId', reservation.vehicle_unit_id,
            'status', 'released',
            'replayed', true
        );
    END IF;

    IF reservation.status NOT IN ('held', 'confirmed') THEN
        RAISE EXCEPTION 'reservation cannot be compensated from status %', reservation.status
            USING ERRCODE = '23514';
    END IF;

    UPDATE public.vehicle_reservations
    SET status = 'released',
        released_at = now_at,
        version = version + 1,
        updated_at = now_at
    WHERE tenant_id = p_tenant_id
      AND reservation_id = p_reservation_id;

    event_id := gen_random_uuid();
    response := jsonb_build_object(
        'reservationId', reservation.reservation_id,
        'orderId', reservation.order_id,
        'vehicleUnitId', reservation.vehicle_unit_id,
        'vehicleVariantId', reservation.vehicle_variant_id,
        'status', 'released'
    );
    envelope := jsonb_build_object(
        'eventId', event_id,
        'eventType', 'reservation.released.v1',
        'schemaVersion', 1,
        'producer', 'vehicle-reservation-service',
        'aggregate', jsonb_build_object('type', 'VehicleReservation', 'id', reservation.reservation_id),
        'tenantId', p_tenant_id,
        'occurredAt', now_at,
        'data', response
    );

    INSERT INTO public.vehicle_reservation_audit_events (
        tenant_id, event_id, reservation_id, actor_id, event_type, payload, occurred_at
    ) VALUES (
        p_tenant_id, event_id, reservation.reservation_id, 'order-saga',
        'ReservationReleased', envelope, now_at
    );
    INSERT INTO public.vehicle_reservation_outbox (
        tenant_id, event_id, reservation_id, event_type, payload, occurred_at
    ) VALUES (
        p_tenant_id, event_id, reservation.reservation_id,
        'reservation.released.v1', envelope, now_at
    );

    RETURN response;
END
$function$;

ALTER FUNCTION public.vehicle_reservation_release_for_order(VARCHAR, UUID, UUID)
    OWNER TO autoflow_reservation_stock_owner;
GRANT EXECUTE ON FUNCTION public.vehicle_reservation_release_for_order(VARCHAR, UUID, UUID)
    TO autoflow_reservation_worker;

REVOKE CREATE ON SCHEMA public FROM autoflow_reservation_stock_owner;
