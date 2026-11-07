DO $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname='autoflow_billing') THEN CREATE ROLE autoflow_billing LOGIN PASSWORD 'local-only-billing-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS; END IF;
END $$;
ALTER ROLE autoflow_billing WITH LOGIN PASSWORD 'local-only-billing-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_billing;
GRANT USAGE ON SCHEMA public TO autoflow_billing;
CREATE TABLE IF NOT EXISTS billing_invoice_sequences(tenant_id VARCHAR(128) NOT NULL,fiscal_year SMALLINT NOT NULL,last_number BIGINT NOT NULL DEFAULT 0,PRIMARY KEY(tenant_id,fiscal_year));
CREATE TABLE IF NOT EXISTS billing_invoices(tenant_id VARCHAR(128) NOT NULL,invoice_id UUID NOT NULL,invoice_number VARCHAR(40) NOT NULL,payment_id UUID NOT NULL,order_id UUID NOT NULL,currency CHAR(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),gross_minor BIGINT NOT NULL CHECK(gross_minor>0),net_minor BIGINT NOT NULL,tax_minor BIGINT NOT NULL,tax_rate_basis_points INTEGER NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'issued',billing_snapshot JSONB NOT NULL DEFAULT '{}'::jsonb,source_event_id UUID NOT NULL,issued_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,due_at TIMESTAMPTZ NULL,paid_at TIMESTAMPTZ NULL,PRIMARY KEY(tenant_id,invoice_id),UNIQUE(tenant_id,invoice_number),UNIQUE(tenant_id,payment_id),UNIQUE(tenant_id,source_event_id));
CREATE INDEX IF NOT EXISTS idx_billing_invoices_tenant_issued ON billing_invoices(tenant_id,issued_at DESC);
CREATE TABLE IF NOT EXISTS billing_credit_note_sequences(tenant_id VARCHAR(128) NOT NULL,fiscal_year SMALLINT NOT NULL,last_number BIGINT NOT NULL DEFAULT 0,PRIMARY KEY(tenant_id,fiscal_year));
CREATE TABLE IF NOT EXISTS billing_credit_notes(tenant_id VARCHAR(128) NOT NULL,credit_note_id UUID NOT NULL,credit_note_number VARCHAR(40) NOT NULL,invoice_id UUID NOT NULL,payment_id UUID NOT NULL,order_id UUID NOT NULL,currency CHAR(3) NOT NULL,gross_minor BIGINT NOT NULL CHECK(gross_minor>0),net_minor BIGINT NOT NULL,tax_minor BIGINT NOT NULL,tax_rate_basis_points INTEGER NOT NULL,reason VARCHAR(255) NOT NULL,source_event_id UUID NOT NULL,issued_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(tenant_id,credit_note_id),UNIQUE(tenant_id,credit_note_number),UNIQUE(tenant_id,source_event_id),FOREIGN KEY(tenant_id,invoice_id) REFERENCES billing_invoices(tenant_id,invoice_id));
CREATE INDEX IF NOT EXISTS idx_billing_credit_notes_tenant_issued ON billing_credit_notes(tenant_id,issued_at DESC);
CREATE TABLE IF NOT EXISTS billing_event_inbox(tenant_id VARCHAR(128) NOT NULL,event_id UUID NOT NULL,event_type VARCHAR(100) NOT NULL,processed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(tenant_id,event_id));
DO $$
DECLARE t TEXT;
BEGIN
 FOREACH t IN ARRAY ARRAY['billing_invoice_sequences','billing_invoices','billing_credit_note_sequences','billing_credit_notes','billing_event_inbox'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);EXECUTE format('DROP POLICY IF EXISTS %I ON %I',t||'_tenant_isolation',t);
  EXECUTE format('CREATE POLICY %I ON %I USING (tenant_id=current_setting(''app.tenant_id'',true)) WITH CHECK (tenant_id=current_setting(''app.tenant_id'',true))',t||'_tenant_isolation',t);
 END LOOP;
END $$;
GRANT SELECT,INSERT,UPDATE ON billing_invoice_sequences,billing_credit_note_sequences,billing_invoices TO autoflow_billing;
GRANT SELECT,INSERT ON billing_credit_notes,billing_event_inbox TO autoflow_billing;
