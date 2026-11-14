-- Enforce that every reservation owns a real, available physical stock unit.
-- Earlier trigger versions silently allowed reservations for missing stock rows.
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
            updated_at = clock_timestamp()
        WHERE tenant_id = NEW.tenant_id
          AND vehicle_unit_id = NEW.vehicle_unit_id
          AND vehicle_variant_id = NEW.vehicle_variant_id
          AND status = 'available';

        IF NOT FOUND THEN
            RAISE EXCEPTION 'physical stock unit does not exist, is unavailable, or does not match tenant and variant'
                USING ERRCODE = '23503';
        END IF;
    ELSIF OLD.status IN ('held', 'confirmed') AND NEW.status IN ('expired', 'released') THEN
        UPDATE public.vehicle_stock_units
        SET status = 'available',
            reservation_id = NULL,
            order_id = NULL,
            updated_at = clock_timestamp()
        WHERE tenant_id = OLD.tenant_id
          AND vehicle_unit_id = OLD.vehicle_unit_id
          AND reservation_id = OLD.reservation_id
          AND status = 'reserved';

        IF NOT FOUND THEN
            RAISE EXCEPTION 'reserved physical stock unit is missing or no longer owned by this reservation'
                USING ERRCODE = '23503';
        END IF;
    ELSIF OLD.status IN ('held', 'confirmed') AND NEW.status = 'fulfilled' THEN
        UPDATE public.vehicle_stock_units
        SET status = 'sold',
            reservation_id = NULL,
            order_id = NULL,
            updated_at = clock_timestamp()
        WHERE tenant_id = OLD.tenant_id
          AND vehicle_unit_id = OLD.vehicle_unit_id
          AND reservation_id = OLD.reservation_id
          AND status = 'reserved';

        IF NOT FOUND THEN
            RAISE EXCEPTION 'reserved physical stock unit is missing or no longer owned by this reservation'
                USING ERRCODE = '23503';
        END IF;
    END IF;

    RETURN NEW;
END
$function$;

ALTER FUNCTION public.vehicle_stock_sync_reservation() OWNER TO autoflow_reservation_stock_owner;
