-- Apply a fulfilled reservation to Inventory's authoritative physical unit.
DO $roles$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_inventory_reservation_consumer') THEN
        CREATE ROLE autoflow_inventory_reservation_consumer LOGIN PASSWORD 'local-only-inventory-reservation-consumer-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_inventory_reservation_owner') THEN
        CREATE ROLE autoflow_inventory_reservation_owner NOLOGIN
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT BYPASSRLS;
    END IF;
END
$roles$;

ALTER ROLE autoflow_inventory_reservation_consumer WITH LOGIN PASSWORD 'local-only-inventory-reservation-consumer-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
ALTER ROLE autoflow_inventory_reservation_owner NOLOGIN
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT BYPASSRLS;

GRANT CONNECT ON DATABASE autoflow TO autoflow_inventory_reservation_consumer;
GRANT USAGE ON SCHEMA public TO autoflow_inventory_reservation_consumer, autoflow_inventory_reservation_owner;
GRANT SELECT, UPDATE ON public.inventory_units TO autoflow_inventory_reservation_owner;
GRANT INSERT ON public.inventory_movements, public.inventory_audit_events, public.inventory_outbox_events TO autoflow_inventory_reservation_owner;

CREATE TABLE IF NOT EXISTS public.inventory_reservation_inbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    reservation_id UUID NOT NULL,
    inventory_unit_id UUID NOT NULL,
    payload_hash CHAR(32) NOT NULL,
    processed_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, event_id),
    UNIQUE (tenant_id, reservation_id)
);
ALTER TABLE public.inventory_reservation_inbox OWNER TO autoflow_inventory_reservation_owner;
GRANT SELECT, INSERT ON public.inventory_reservation_inbox TO autoflow_inventory_reservation_owner;
ALTER TABLE public.inventory_reservation_inbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.inventory_reservation_inbox FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS inventory_reservation_inbox_tenant_isolation ON public.inventory_reservation_inbox;
CREATE POLICY inventory_reservation_inbox_tenant_isolation ON public.inventory_reservation_inbox
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));

CREATE OR REPLACE FUNCTION public.inventory_apply_reservation_fulfilled(
    p_tenant_id VARCHAR(128),
    p_event_id UUID,
    p_reservation_id UUID,
    p_inventory_unit_id UUID,
    p_vehicle_variant_id UUID,
    p_order_id UUID,
    p_reservation_version INTEGER
)
RETURNS BOOLEAN
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
DECLARE
    existing_hash CHAR(32);
    event_hash CHAR(32);
    unit RECORD;
    inventory_event_id UUID;
    now_at TIMESTAMPTZ := clock_timestamp();
    envelope JSONB;
BEGIN
    IF p_tenant_id IS NULL OR p_tenant_id !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
       OR p_reservation_version < 1 THEN
        RAISE EXCEPTION 'invalid reservation fulfillment event data' USING ERRCODE = '22023';
    END IF;

    event_hash := md5(concat_ws('|', p_tenant_id, p_event_id, p_reservation_id,
        p_inventory_unit_id, p_vehicle_variant_id, p_order_id, p_reservation_version));

    SELECT payload_hash INTO existing_hash
    FROM public.inventory_reservation_inbox
    WHERE tenant_id = p_tenant_id AND event_id = p_event_id;

    IF FOUND THEN
        IF existing_hash <> event_hash THEN
            RAISE EXCEPTION 'reservation event ID was reused with a different payload' USING ERRCODE = '23514';
        END IF;
        RETURN FALSE;
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.inventory_reservation_inbox
        WHERE tenant_id = p_tenant_id AND reservation_id = p_reservation_id
    ) THEN
        RAISE EXCEPTION 'reservation was already applied using another event ID' USING ERRCODE = '23505';
    END IF;

    SELECT inventory_unit_id, vehicle_variant_id, vin, status, version
    INTO unit
    FROM public.inventory_units
    WHERE tenant_id = p_tenant_id AND inventory_unit_id = p_inventory_unit_id
      AND vehicle_variant_id = p_vehicle_variant_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Inventory unit or vehicle variant does not exist for fulfilled reservation'
            USING ERRCODE = '23503';
    END IF;

    IF unit.status <> 'available' THEN
        RAISE EXCEPTION 'Inventory unit cannot be sold from current status %', unit.status
            USING ERRCODE = '23514';
    END IF;

    INSERT INTO public.inventory_reservation_inbox
        (tenant_id, event_id, reservation_id, inventory_unit_id, payload_hash)
    VALUES
        (p_tenant_id, p_event_id, p_reservation_id, p_inventory_unit_id, event_hash);

    UPDATE public.inventory_units
    SET status = 'sold',
        version = version + 1,
        updated_by = 'system:reservation-fulfillment',
        updated_at = now_at
    WHERE tenant_id = p_tenant_id AND inventory_unit_id = p_inventory_unit_id;

    inventory_event_id := gen_random_uuid();
    envelope := jsonb_build_object(
        'eventId', inventory_event_id,
        'eventType', 'inventory.status.changed.v1',
        'schemaVersion', 1,
        'producer', 'inventory-service',
        'aggregate', jsonb_build_object('type', 'InventoryUnit', 'id', p_inventory_unit_id),
        'tenantId', p_tenant_id,
        'occurredAt', now_at,
        'data', jsonb_build_object(
            'inventoryUnitId', p_inventory_unit_id,
            'fromStatus', 'available',
            'toStatus', 'sold',
            'fromLocationCode', NULL,
            'toLocationCode', NULL,
            'vehicleVariantId', p_vehicle_variant_id,
            'vin', unit.vin,
            'version', unit.version + 1,
            'reason', 'reservation.fulfilled',
            'reservationId', p_reservation_id,
            'orderId', p_order_id
        )
    );

    INSERT INTO public.inventory_movements
        (tenant_id, movement_id, inventory_unit_id, from_status, to_status, reason, actor_id, occurred_at)
    VALUES
        (p_tenant_id, gen_random_uuid(), p_inventory_unit_id, 'available', 'sold',
         'reservation.fulfilled', 'system:reservation-fulfillment', now_at);

    INSERT INTO public.inventory_audit_events
        (tenant_id, event_id, inventory_unit_id, actor_id, event_type, payload, occurred_at)
    VALUES
        (p_tenant_id, inventory_event_id, p_inventory_unit_id, 'system:reservation-fulfillment',
         'InventoryStatusChanged', envelope, now_at);

    INSERT INTO public.inventory_outbox_events
        (tenant_id, event_id, inventory_unit_id, event_type, payload, occurred_at)
    VALUES
        (p_tenant_id, inventory_event_id, p_inventory_unit_id,
         'inventory.status.changed.v1', envelope, now_at);

    RETURN TRUE;
END
$function$;

ALTER FUNCTION public.inventory_apply_reservation_fulfilled(VARCHAR,UUID,UUID,UUID,UUID,UUID,INTEGER)
    OWNER TO autoflow_inventory_reservation_owner;
REVOKE ALL ON FUNCTION public.inventory_apply_reservation_fulfilled(VARCHAR,UUID,UUID,UUID,UUID,UUID,INTEGER) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.inventory_apply_reservation_fulfilled(VARCHAR,UUID,UUID,UUID,UUID,UUID,INTEGER)
    TO autoflow_inventory_reservation_consumer;
