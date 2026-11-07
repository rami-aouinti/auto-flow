-- Add a tenant-scoped reference to Customer Service without a cross-database foreign key.
-- Nullable columns preserve historical orders created before customer linkage.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS customer_id UUID NULL,
    ADD COLUMN IF NOT EXISTS customer_version INTEGER NULL,
    ADD COLUMN IF NOT EXISTS customer_snapshot JSONB NOT NULL DEFAULT '{}'::jsonb;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'orders_customer_snapshot_object'
          AND conrelid = 'orders'::regclass
    ) THEN
        ALTER TABLE orders
            ADD CONSTRAINT orders_customer_snapshot_object
            CHECK (jsonb_typeof(customer_snapshot) = 'object');
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'orders_customer_version_positive'
          AND conrelid = 'orders'::regclass
    ) THEN
        ALTER TABLE orders
            ADD CONSTRAINT orders_customer_version_positive
            CHECK (customer_version IS NULL OR customer_version > 0);
    END IF;
END
$$;

CREATE INDEX IF NOT EXISTS idx_orders_tenant_customer_created
    ON orders (tenant_id, customer_id, created_at DESC)
    WHERE customer_id IS NOT NULL;
