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


-- Dedicated publisher access: the worker can execute claim/ack/retry functions
-- but cannot read or mutate inventory business tables directly.
DO $roles$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='autoflow_inventory_publisher') THEN
  CREATE ROLE autoflow_inventory_publisher LOGIN PASSWORD 'local-only-inventory-publisher-password'
   NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
 END IF;
 IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='autoflow_inventory_outbox_owner') THEN
  CREATE ROLE autoflow_inventory_outbox_owner NOLOGIN
   NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT BYPASSRLS;
 END IF;
END
$roles$;
ALTER ROLE autoflow_inventory_publisher WITH LOGIN PASSWORD 'local-only-inventory-publisher-password'
 NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
ALTER ROLE autoflow_inventory_outbox_owner NOLOGIN
 NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT BYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_inventory_publisher;
GRANT USAGE ON SCHEMA public TO autoflow_inventory_publisher, autoflow_inventory_outbox_owner;
GRANT SELECT, UPDATE ON inventory_outbox_events TO autoflow_inventory_outbox_owner;

ALTER TABLE inventory_outbox_events
 ADD COLUMN IF NOT EXISTS next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ADD COLUMN IF NOT EXISTS locked_until TIMESTAMPTZ,
 ADD COLUMN IF NOT EXISTS last_error VARCHAR(500),
 ADD COLUMN IF NOT EXISTS dead_lettered_at TIMESTAMPTZ;

CREATE OR REPLACE FUNCTION public.inventory_outbox_claim()
RETURNS TABLE(tenant_id VARCHAR, event_id UUID, event_type VARCHAR, payload JSONB, attempts INTEGER)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
BEGIN
 RETURN QUERY
 WITH candidate AS (
  SELECT o.tenant_id, o.event_id
  FROM public.inventory_outbox_events o
  WHERE o.published_at IS NULL
    AND o.dead_lettered_at IS NULL
    AND o.attempts < 12
    AND o.next_attempt_at <= clock_timestamp()
    AND (o.locked_until IS NULL OR o.locked_until < clock_timestamp())
  ORDER BY o.occurred_at, o.event_id
  FOR UPDATE SKIP LOCKED
  LIMIT 1
 )
 UPDATE public.inventory_outbox_events o
 SET attempts = o.attempts + 1,
     locked_until = clock_timestamp() + INTERVAL '60 seconds'
 FROM candidate c
 WHERE o.tenant_id = c.tenant_id AND o.event_id = c.event_id
 RETURNING o.tenant_id, o.event_id, o.event_type, o.payload, o.attempts;
END
$function$;
ALTER FUNCTION public.inventory_outbox_claim() OWNER TO autoflow_inventory_outbox_owner;

CREATE OR REPLACE FUNCTION public.inventory_outbox_mark_published(p_tenant VARCHAR, p_event UUID)
RETURNS BOOLEAN
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
DECLARE changed INTEGER;
BEGIN
 UPDATE public.inventory_outbox_events
 SET published_at = clock_timestamp(), locked_until = NULL, last_error = NULL
 WHERE tenant_id = p_tenant AND event_id = p_event
   AND published_at IS NULL AND dead_lettered_at IS NULL;
 GET DIAGNOSTICS changed = ROW_COUNT;
 RETURN changed = 1;
END
$function$;
ALTER FUNCTION public.inventory_outbox_mark_published(VARCHAR, UUID) OWNER TO autoflow_inventory_outbox_owner;

CREATE OR REPLACE FUNCTION public.inventory_outbox_schedule_retry(p_tenant VARCHAR, p_event UUID, p_error VARCHAR)
RETURNS VOID
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
SET row_security = off
AS $function$
BEGIN
 UPDATE public.inventory_outbox_events
 SET locked_until = NULL,
     last_error = left(p_error, 500),
     dead_lettered_at = CASE WHEN attempts >= 12 THEN clock_timestamp() ELSE NULL END,
     next_attempt_at = clock_timestamp() + make_interval(secs => LEAST(3600, (5 * power(2, GREATEST(attempts - 1, 0)))::INTEGER)
 WHERE tenant_id = p_tenant AND event_id = p_event
   AND published_at IS NULL AND dead_lettered_at IS NULL;
END
$function$;
ALTER FUNCTION public.inventory_outbox_schedule_retry(VARCHAR, UUID, VARCHAR) OWNER TO autoflow_inventory_outbox_owner;

REVOKE ALL ON FUNCTION public.inventory_outbox_claim() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.inventory_outbox_mark_published(VARCHAR, UUID) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.inventory_outbox_schedule_retry(VARCHAR, UUID, VARCHAR) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.inventory_outbox_claim() TO autoflow_inventory_publisher;
GRANT EXECUTE ON FUNCTION public.inventory_outbox_mark_published(VARCHAR, UUID) TO autoflow_inventory_publisher;
GRANT EXECUTE ON FUNCTION public.inventory_outbox_schedule_retry(VARCHAR, UUID, VARCHAR) TO autoflow_inventory_publisher;
