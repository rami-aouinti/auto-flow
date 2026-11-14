ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS billing_snapshot JSONB NOT NULL DEFAULT '{}'::jsonb;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'payments_billing_snapshot_object'
          AND conrelid = 'payments'::regclass
    ) THEN
        ALTER TABLE payments
            ADD CONSTRAINT payments_billing_snapshot_object
            CHECK (jsonb_typeof(billing_snapshot) = 'object');
    END IF;
END
$$;

GRANT UPDATE (billing_snapshot) ON payments TO autoflow_payment;
