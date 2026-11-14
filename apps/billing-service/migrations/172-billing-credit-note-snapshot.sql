ALTER TABLE billing_credit_notes
    ADD COLUMN IF NOT EXISTS billing_snapshot JSONB NOT NULL DEFAULT '{}'::jsonb;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'billing_credit_notes_snapshot_object'
          AND conrelid = 'billing_credit_notes'::regclass
    ) THEN
        ALTER TABLE billing_credit_notes
            ADD CONSTRAINT billing_credit_notes_snapshot_object
            CHECK (jsonb_typeof(billing_snapshot) = 'object');
    END IF;
END
$$;

GRANT UPDATE (billing_snapshot) ON billing_credit_notes TO autoflow_billing;
