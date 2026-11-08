-- Confirming a held reservation is an idempotent, auditable transition.
-- Stock remains allocated; only the reservation lifecycle advances.
CREATE OR REPLACE FUNCTION public.vehicle_reservation_confirm_for_order(
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

    IF reservation.status = 'confirmed' THEN
        RETURN jsonb_build_object(
            'reservationId', reservation.reservation_id,
            'orderId', reservation.order_id,
            'vehicleUnitId', reservation.vehicle_unit_id,
            'vehicleVariantId', reservation.vehicle_variant_id,
            'status', 'confirmed',
            'confirmedAt', reservation.confirmed_at,
            'replayed', true
        );
    END IF;

    IF reservation.status <> 'held' OR reservation.expires_at <= now_at THEN
        RAISE EXCEPTION 'reservation cannot be confirmed from status % or after expiry', reservation.status
            USING ERRCODE = '23514';
    END IF;

    UPDATE public.vehicle_reservations
    SET status = 'confirmed',
        confirmed_at = now_at,
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
        'status', 'confirmed',
        'confirmedAt', now_at,
        'version', reservation.version + 1
    );
    envelope := jsonb_build_object(
        'eventId', event_id,
        'eventType', 'reservation.confirmed.v1',
        'schemaVersion', 1,
        'producer', 'vehicle-reservation-service',
        'aggregate', jsonb_build_object('type', 'VehicleReservation', 'id', reservation.reservation_id),
        'tenantId', p_tenant_id,
        'occurredAt', now_at,
        'data', response
    );

    INSERT INTO public.vehicle_reservation_audit_events
        (tenant_id, event_id, reservation_id, actor_id, event_type, payload, occurred_at)
    VALUES
        (p_tenant_id, event_id, reservation.reservation_id, 'order-saga',
         'ReservationConfirmed', envelope, now_at);

    INSERT INTO public.vehicle_reservation_outbox
        (tenant_id, event_id, reservation_id, event_type, payload, occurred_at)
    VALUES
        (p_tenant_id, event_id, reservation.reservation_id,
         'reservation.confirmed.v1', envelope, now_at);

    RETURN response;
END
$function$;

ALTER FUNCTION public.vehicle_reservation_confirm_for_order(VARCHAR, UUID, UUID)
    OWNER TO autoflow_reservation_stock_owner;
GRANT EXECUTE ON FUNCTION public.vehicle_reservation_confirm_for_order(VARCHAR, UUID, UUID)
    TO autoflow_reservation_worker;
