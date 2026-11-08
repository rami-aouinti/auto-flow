-- Reservation confirmation becomes a third fulfillment prerequisite.
-- The reservation service remains the source of truth for the physical unit.
ALTER TABLE fulfillment_records
    ADD COLUMN IF NOT EXISTS reservation_confirmed_at TIMESTAMPTZ NULL;

DO $constraint$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fulfillment_requires_confirmed_reservation'
          AND conrelid = 'public.fulfillment_records'::regclass
    ) THEN
        ALTER TABLE fulfillment_records
            ADD CONSTRAINT fulfillment_requires_confirmed_reservation
            CHECK (
                status IN ('awaiting_prerequisites', 'cancelled')
                OR reservation_confirmed_at IS NOT NULL
            );
    END IF;
END
$constraint$;
