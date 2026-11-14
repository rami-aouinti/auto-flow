-- Inventory is authoritative for physical unit identity and operational status.
-- Reservation keeps only an allocation projection and never re-opens blocked/sold stock.
ALTER TABLE public.vehicle_stock_units
    ADD COLUMN IF NOT EXISTS inventory_status VARCHAR(24) NOT NULL DEFAULT 'available',
    ADD COLUMN IF NOT EXISTS inventory_version INTEGER NOT NULL DEFAULT 0;

ALTER TABLE public.vehicle_stock_units
    DROP CONSTRAINT IF EXISTS vehicle_stock_units_inventory_status_check;
ALTER TABLE public.vehicle_stock_units
    ADD CONSTRAINT vehicle_stock_units_inventory_status_check
    CHECK (inventory_status IN ('available','blocked','in_transit','delivered','sold','maintenance','quarantined'));
ALTER TABLE public.vehicle_stock_units
    DROP CONSTRAINT IF EXISTS vehicle_stock_units_inventory_version_check;
ALTER TABLE public.vehicle_stock_units
    ADD CONSTRAINT vehicle_stock_units_inventory_version_check CHECK (inventory_version >= 0);

CREATE TABLE IF NOT EXISTS public.vehicle_stock_inventory_inbox (
    tenant_id VARCHAR(128) NOT NULL,
    event_id UUID NOT NULL,
    inventory_unit_id UUID NOT NULL,
    inventory_version INTEGER NOT NULL,
    processed_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, event_id)
);
ALTER TABLE public.vehicle_stock_inventory_inbox OWNER TO autoflow_reservation_stock_owner;
GRANT SELECT, INSERT ON public.vehicle_stock_inventory_inbox TO autoflow_reservation_stock_owner;

CREATE OR REPLACE FUNCTION public.vehicle_stock_apply_inventory_event(
    p_tenant_id VARCHAR(128),
    p_event_id UUID,
    p_inventory_unit_id UUID,
    p_vehicle_variant_id UUID,
    p_vin VARCHAR(17),
    p_inventory_status VARCHAR(24),
    p_inventory_version INTEGER
)
RETURNS BOOLEAN
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
DECLARE
    inserted_count INTEGER;
BEGIN
    IF p_tenant_id IS NULL OR p_tenant_id !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
       OR p_inventory_version < 1
       OR p_inventory_status NOT IN ('available','blocked','in_transit','delivered','sold','maintenance','quarantined')
       OR p_vin IS NULL OR p_vin !~ '^[A-HJ-NPR-Z0-9]{17}$' THEN
        RAISE EXCEPTION 'invalid Inventory event data' USING ERRCODE = '22023';
    END IF;

    INSERT INTO public.vehicle_stock_inventory_inbox (tenant_id,event_id,inventory_unit_id,inventory_version)
    VALUES (p_tenant_id,p_event_id,p_inventory_unit_id,p_inventory_version)
    ON CONFLICT (tenant_id,event_id) DO NOTHING;
    GET DIAGNOSTICS inserted_count = ROW_COUNT;
    IF inserted_count = 0 THEN
        RETURN FALSE;
    END IF;

    INSERT INTO public.vehicle_stock_units (
        tenant_id,vehicle_unit_id,vehicle_variant_id,vin,status,inventory_status,inventory_version
    ) VALUES (
        p_tenant_id,p_inventory_unit_id,p_vehicle_variant_id,p_vin,
        CASE WHEN p_inventory_status = 'available' THEN 'available'
             WHEN p_inventory_status = 'sold' THEN 'sold'
             ELSE 'unavailable' END,
        p_inventory_status,p_inventory_version
    )
    ON CONFLICT (tenant_id,vehicle_unit_id) DO UPDATE
    SET vehicle_variant_id = EXCLUDED.vehicle_variant_id,
        vin = EXCLUDED.vin,
        inventory_status = EXCLUDED.inventory_status,
        inventory_version = EXCLUDED.inventory_version,
        status = CASE
            WHEN public.vehicle_stock_units.status IN ('reserved','sold') THEN public.vehicle_stock_units.status
            WHEN EXCLUDED.inventory_status = 'available' THEN 'available'
            WHEN EXCLUDED.inventory_status = 'sold' THEN 'sold'
            ELSE 'unavailable'
        END,
        updated_at = clock_timestamp()
    WHERE public.vehicle_stock_units.inventory_version < EXCLUDED.inventory_version
      AND public.vehicle_stock_units.vehicle_variant_id = EXCLUDED.vehicle_variant_id;

    RETURN TRUE;
END
$function$;
ALTER FUNCTION public.vehicle_stock_apply_inventory_event(VARCHAR,UUID,UUID,UUID,VARCHAR,VARCHAR,INTEGER)
    OWNER TO autoflow_reservation_stock_owner;
REVOKE ALL ON FUNCTION public.vehicle_stock_apply_inventory_event(VARCHAR,UUID,UUID,UUID,VARCHAR,VARCHAR,INTEGER) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.vehicle_stock_apply_inventory_event(VARCHAR,UUID,UUID,UUID,VARCHAR,VARCHAR,INTEGER)
    TO autoflow_reservation_worker;

-- Reservation release restores availability only when Inventory still says available.
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
        SET status = 'reserved', reservation_id = NEW.reservation_id,
            order_id = NEW.order_id, updated_at = clock_timestamp()
        WHERE tenant_id = NEW.tenant_id AND vehicle_unit_id = NEW.vehicle_unit_id
          AND vehicle_variant_id = NEW.vehicle_variant_id
          AND status = 'available' AND inventory_status = 'available';
        IF NOT FOUND THEN
            RAISE EXCEPTION 'physical stock unit does not exist, is unavailable, or does not match tenant and variant'
                USING ERRCODE = '23503';
        END IF;
    ELSIF OLD.status IN ('held','confirmed') AND NEW.status IN ('expired','released') THEN
        UPDATE public.vehicle_stock_units
        SET status = CASE
                WHEN inventory_status = 'available' THEN 'available'
                WHEN inventory_status = 'sold' THEN 'sold'
                ELSE 'unavailable'
            END,
            reservation_id = NULL, order_id = NULL, updated_at = clock_timestamp()
        WHERE tenant_id = OLD.tenant_id AND vehicle_unit_id = OLD.vehicle_unit_id
          AND reservation_id = OLD.reservation_id AND status = 'reserved';
        IF NOT FOUND THEN
            RAISE EXCEPTION 'reserved physical stock unit is missing or no longer owned by this reservation'
                USING ERRCODE = '23503';
        END IF;
    ELSIF OLD.status IN ('held','confirmed') AND NEW.status = 'fulfilled' THEN
        UPDATE public.vehicle_stock_units
        SET status = 'sold', inventory_status = 'sold',
            reservation_id = NULL, order_id = NULL, updated_at = clock_timestamp()
        WHERE tenant_id = OLD.tenant_id AND vehicle_unit_id = OLD.vehicle_unit_id
          AND reservation_id = OLD.reservation_id AND status = 'reserved';
        IF NOT FOUND THEN
            RAISE EXCEPTION 'reserved physical stock unit is missing or no longer owned by this reservation'
                USING ERRCODE = '23503';
        END IF;
    END IF;
    RETURN NEW;
END
$function$;
ALTER FUNCTION public.vehicle_stock_sync_reservation() OWNER TO autoflow_reservation_stock_owner;

-- Holds are possible only for units whose latest Inventory state is available.
