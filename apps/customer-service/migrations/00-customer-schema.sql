DO $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname='autoflow_customer') THEN
  CREATE ROLE autoflow_customer LOGIN PASSWORD 'local-only-customer-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
 END IF;
END $$;
ALTER ROLE autoflow_customer WITH LOGIN PASSWORD 'local-only-customer-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_customer;
GRANT USAGE ON SCHEMA public TO autoflow_customer;

CREATE TABLE IF NOT EXISTS customer_records(
 tenant_id VARCHAR(128) NOT NULL,customer_id UUID NOT NULL,customer_type VARCHAR(16) NOT NULL CHECK(customer_type IN('individual','company')),
 first_name VARCHAR(100),last_name VARCHAR(100),legal_name VARCHAR(180),email VARCHAR(254),email_normalized VARCHAR(254),
 phone VARCHAR(40),vat_id VARCHAR(40),locale VARCHAR(10) NOT NULL DEFAULT 'de-DE',
 status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN('active','inactive','archived')),version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
 created_by VARCHAR(255) NOT NULL,updated_by VARCHAR(255) NOT NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,archived_at TIMESTAMPTZ,PRIMARY KEY(tenant_id,customer_id),
 CHECK((customer_type='individual' AND first_name IS NOT NULL AND last_name IS NOT NULL) OR (customer_type='company' AND legal_name IS NOT NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_customer_tenant_email_active ON customer_records(tenant_id,email_normalized) WHERE email_normalized IS NOT NULL AND archived_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_customer_tenant_created ON customer_records(tenant_id,created_at DESC) WHERE archived_at IS NULL;
CREATE TABLE IF NOT EXISTS customer_addresses(
 tenant_id VARCHAR(128) NOT NULL,address_id UUID NOT NULL,customer_id UUID NOT NULL,address_type VARCHAR(20) NOT NULL CHECK(address_type IN('billing','shipping','residential','business')),
 label VARCHAR(80),line1 VARCHAR(180) NOT NULL,line2 VARCHAR(180),postal_code VARCHAR(20) NOT NULL,city VARCHAR(120) NOT NULL,region VARCHAR(120),
 country_code CHAR(2) NOT NULL CHECK(country_code ~ '^[A-Z]{2}$'),is_default BOOLEAN NOT NULL DEFAULT false,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,deleted_at TIMESTAMPTZ,
 PRIMARY KEY(tenant_id,address_id),FOREIGN KEY(tenant_id,customer_id) REFERENCES customer_records(tenant_id,customer_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_customer_default_address ON customer_addresses(tenant_id,customer_id,address_type) WHERE is_default=true AND deleted_at IS NULL;
CREATE TABLE IF NOT EXISTS customer_consents(
 tenant_id VARCHAR(128) NOT NULL,customer_id UUID NOT NULL,purpose VARCHAR(64) NOT NULL,granted BOOLEAN NOT NULL,source VARCHAR(80) NOT NULL,
 recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(tenant_id,customer_id,purpose),
 FOREIGN KEY(tenant_id,customer_id) REFERENCES customer_records(tenant_id,customer_id)
);
CREATE TABLE IF NOT EXISTS customer_audit_events(
 tenant_id VARCHAR(128) NOT NULL,event_id UUID NOT NULL,customer_id UUID NOT NULL,actor_id VARCHAR(255) NOT NULL,event_type VARCHAR(80) NOT NULL,
 payload JSONB NOT NULL DEFAULT '{}'::jsonb,occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(tenant_id,event_id),
 FOREIGN KEY(tenant_id,customer_id) REFERENCES customer_records(tenant_id,customer_id)
);
CREATE INDEX IF NOT EXISTS idx_customer_audit_history ON customer_audit_events(tenant_id,customer_id,occurred_at DESC);
CREATE TABLE IF NOT EXISTS customer_idempotency_keys(
 tenant_id VARCHAR(128) NOT NULL,idempotency_key VARCHAR(128) NOT NULL,request_hash CHAR(64) NOT NULL,response_body JSONB NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(tenant_id,idempotency_key)
);
DO $$
DECLARE t TEXT;
BEGIN
 FOREACH t IN ARRAY ARRAY['customer_records','customer_addresses','customer_consents','customer_audit_events','customer_idempotency_keys'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('DROP POLICY IF EXISTS %I ON %I',t||'_tenant_isolation',t);
  EXECUTE format('CREATE POLICY %I ON %I USING(tenant_id=current_setting(''app.tenant_id'',true)) WITH CHECK(tenant_id=current_setting(''app.tenant_id'',true))',t||'_tenant_isolation',t);
 END LOOP;
END $$;
GRANT SELECT,INSERT,UPDATE ON customer_records,customer_addresses,customer_consents TO autoflow_customer;
GRANT SELECT,INSERT ON customer_audit_events,customer_idempotency_keys TO autoflow_customer;
