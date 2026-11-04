DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'pricing_policies_supported_calculation'
          AND conrelid = 'pricing_policies'::regclass
    ) THEN
        ALTER TABLE pricing_policies
            ADD CONSTRAINT pricing_policies_supported_calculation
            CHECK (calculation_version = 'lease-annuity-v1');
    END IF;
END
$$;
