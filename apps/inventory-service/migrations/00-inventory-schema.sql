DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='autoflow_inventory') THEN
  CREATE ROLE autoflow_inventory LOGIN PASSWORD 'local-only-inventory-password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
 END IF;
END $$;
ALTER ROLE autoflow_inventory WITH LOGIN PASSWORD 'local-only-inventory-password'
 NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_inventory;
GRANT USAGE ON SCHEMA public TO autoflow_inventory;

CREATE TABLE IF NOT EXISTS inventory_units (
 tenant_id VARCHAR(128) NOT NULL,
 inventory_unit_id UUID NOT NULL,
 vehicle_variant_id UUID NOT NULL,
 vin VARCHAR(17) NOT NULL,
 vin_normalized VARCHAR(17) NOT NULL,
 dealer_id UUID,
 location_code VARCHAR(64),
 country_code CHAR(2),
 status VARCHAR(24) NOT NULL DEFAULT 'available'
   CHECK (status IN ('available','blocked','in_transit','delivered','sold','maintenance','quarantined')),
 condition VARCHAR(16) NOT NULL DEFAULT 'new'
   CHECK (condition IN ('new','used','damaged')),
 mileage_km INTEGER CHECK (mileage_km IS NULL OR mileage_km >= 0),
 first_registration_date DATE,
 acquired_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
 created_by VARCHAR(255) NOT NULL,
 updated_by VARCHAR(255) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (tenant_id, inventory_unit_id),
 UNIQUE (tenant_id, vin_normalized),
 CHECK (vin ~ '^[A-HJ-NPR-Z0-9]{17}$'),
 CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')
);
CREATE INDEX IF NOT EXISTS idx_inventory_variant_status
 ON inventory_units (tenant_id, vehicle_variant_id, status, acquired_at);
CREATE INDEX IF NOT EXISTS idx_inventory_dealer_status
 ON inventory_units (tenant_id, dealer_id, status) WHERE dealer_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS inventory_movements (
 tenant_id VARCHAR(128) NOT NULL,
 movement_id UUID NOT NULL,
 inventory_unit_id UUID NOT NULL,
 from_status VARCHAR(24),
 to_status VARCHAR(24) NOT NULL,
 from_location_code VARCHAR(64),
 to_location_code VARCHAR(64),
 reason VARCHAR(500),
 actor_id VARCHAR(255) NOT NULL,
 occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (tenant_id, movement_id),
 FOREIGN KEY (tenant_id, inventory_unit_id)
   REFERENCES inventory_units(tenant_id, inventory_unit_id)
);
CREATE INDEX IF NOT EXISTS idx_inventory_movements_history
 ON inventory_movements (tenant_id, inventory_unit_id, occurred_at DESC);

CREATE TABLE IF NOT EXISTS inventory_audit_events (
 tenant_id VARCHAR(128) NOT NULL,
 event_id UUID NOT NULL,
 inventory_unit_id UUID NOT NULL,
 actor_id VARCHAR(255) NOT NULL,
 event_type VARCHAR(80) NOT NULL,
 payload JSONB NOT NULL DEFAULT '{}'::jsonb,
 occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (tenant_id, event_id),
 FOREIGN KEY (tenant_id, inventory_unit_id)
   REFERENCES inventory_units(tenant_id, inventory_unit_id)
);
CREATE TABLE IF NOT EXISTS inventory_outbox_events (
 tenant_id VARCHAR(128) NOT NULL,
 event_id UUID NOT NULL,
 inventory_unit_id UUID NOT NULL,
 event_type VARCHAR(100) NOT NULL,
 payload JSONB NOT NULL,
 occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 published_at TIMESTAMPTZ,
 attempts INTEGER NOT NULL DEFAULT 0,
 PRIMARY KEY (tenant_id, event_id),
 FOREIGN KEY (tenant_id, inventory_unit_id)
   REFERENCES inventory_units(tenant_id, inventory_unit_id)
);
CREATE INDEX IF NOT EXISTS idx_inventory_outbox_pending
 ON inventory_outbox_events (occurred_at)
 WHERE published_at IS NULL;
CREATE TABLE IF NOT EXISTS inventory_idempotency_keys (
 tenant_id VARCHAR(128) NOT NULL,
 idempotency_key VARCHAR(128) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 response_body JSONB NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (tenant_id, idempotency_key)
);

DO $$
DECLARE t TEXT;
BEGIN
 FOREACH t IN ARRAY ARRAY[
  'inventory_units','inventory_movements','inventory_audit_events',
  'inventory_outbox_events','inventory_idempotency_keys'
 ] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('DROP POLICY IF EXISTS %I ON %I',t||'_tenant_isolation',t);
  EXECUTE format(
   'CREATE POLICY %I ON %I USING(tenant_id=current_setting(''app.tenant_id'',true)) WITH CHECK(tenant_id=current_setting(''app.tenant_id'',true))',
   t||'_tenant_isolation',t
  );
 END LOOP;
END $$;
GRANT SELECT,INSERT,UPDATE ON inventory_units TO autoflow_inventory;
GRANT SELECT,INSERT ON inventory_movements,inventory_audit_events,inventory_outbox_events,inventory_idempotency_keys TO autoflow_inventory;
