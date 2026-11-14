DO $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname='autoflow_crm') THEN
  CREATE ROLE autoflow_crm LOGIN PASSWORD 'local-only-crm-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
 END IF;
END $$;
ALTER ROLE autoflow_crm WITH LOGIN PASSWORD 'local-only-crm-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_crm;
GRANT USAGE ON SCHEMA public TO autoflow_crm;

CREATE TABLE IF NOT EXISTS crm_applications (
 tenant_id VARCHAR(128) NOT NULL,
 application_id UUID NOT NULL,
 customer_id UUID NOT NULL,
 offer_id UUID,
 vehicle_id VARCHAR(128),
 quote_id VARCHAR(128),
 status VARCHAR(32) NOT NULL DEFAULT 'submitted' CHECK(status IN ('submitted','under_review','information_required','eligible','declined','approved','converted','withdrawn')),
 applicant_message TEXT,
 assigned_advisor_id VARCHAR(255),
 eligibility_status VARCHAR(24) NOT NULL DEFAULT 'pending' CHECK(eligibility_status IN ('pending','in_review','eligible','ineligible','manual_review')),
 eligibility_result JSONB NOT NULL DEFAULT '{}'::jsonb,
 submitted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at TIMESTAMPTZ,
 version INTEGER NOT NULL DEFAULT 1 CHECK(version > 0),
 created_by VARCHAR(255) NOT NULL,
 updated_by VARCHAR(255) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(tenant_id,application_id)
);
CREATE INDEX IF NOT EXISTS idx_crm_applications_tenant_status ON crm_applications(tenant_id,status,submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_applications_customer ON crm_applications(tenant_id,customer_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_applications_advisor ON crm_applications(tenant_id,assigned_advisor_id,status);

CREATE TABLE IF NOT EXISTS crm_tasks (
 tenant_id VARCHAR(128) NOT NULL,
 task_id UUID NOT NULL,
 application_id UUID NOT NULL,
 assigned_to VARCHAR(255) NOT NULL,
 title VARCHAR(180) NOT NULL,
 description TEXT,
 due_at TIMESTAMPTZ,
 status VARCHAR(16) NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','completed','cancelled')),
 completed_at TIMESTAMPTZ,
 created_by VARCHAR(255) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(tenant_id,task_id),
 FOREIGN KEY(tenant_id,application_id) REFERENCES crm_applications(tenant_id,application_id)
);
CREATE INDEX IF NOT EXISTS idx_crm_tasks_assignee ON crm_tasks(tenant_id,assigned_to,status,due_at);

CREATE TABLE IF NOT EXISTS crm_interactions (
 tenant_id VARCHAR(128) NOT NULL,
 interaction_id UUID NOT NULL,
 application_id UUID NOT NULL,
 actor_id VARCHAR(255) NOT NULL,
 channel VARCHAR(24) NOT NULL CHECK(channel IN ('phone','email','meeting','note','system')),
 subject VARCHAR(180) NOT NULL,
 body TEXT,
 occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(tenant_id,interaction_id),
 FOREIGN KEY(tenant_id,application_id) REFERENCES crm_applications(tenant_id,application_id)
);
CREATE INDEX IF NOT EXISTS idx_crm_interactions_application ON crm_interactions(tenant_id,application_id,occurred_at DESC);

CREATE TABLE IF NOT EXISTS crm_audit_events (
 tenant_id VARCHAR(128) NOT NULL,
 event_id UUID NOT NULL,
 application_id UUID NOT NULL,
 actor_id VARCHAR(255) NOT NULL,
 event_type VARCHAR(80) NOT NULL,
 payload JSONB NOT NULL DEFAULT '{}'::jsonb,
 occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(tenant_id,event_id),
 FOREIGN KEY(tenant_id,application_id) REFERENCES crm_applications(tenant_id,application_id)
);

CREATE TABLE IF NOT EXISTS crm_outbox_events (
 tenant_id VARCHAR(128) NOT NULL,
 event_id UUID NOT NULL,
 aggregate_id UUID NOT NULL,
 event_type VARCHAR(100) NOT NULL,
 payload JSONB NOT NULL,
 occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 published_at TIMESTAMPTZ,
 attempts INTEGER NOT NULL DEFAULT 0,
 next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_error TEXT,
 PRIMARY KEY(tenant_id,event_id)
);
CREATE INDEX IF NOT EXISTS idx_crm_outbox_pending ON crm_outbox_events(next_attempt_at,occurred_at) WHERE published_at IS NULL;

CREATE TABLE IF NOT EXISTS crm_idempotency_keys (
 tenant_id VARCHAR(128) NOT NULL,
 actor_id VARCHAR(255) NOT NULL,
 idempotency_key VARCHAR(128) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 response_body JSONB NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(tenant_id,actor_id,idempotency_key)
);

DO $$
DECLARE t TEXT;
BEGIN
 FOREACH t IN ARRAY ARRAY['crm_applications','crm_tasks','crm_interactions','crm_audit_events','crm_outbox_events','crm_idempotency_keys'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON %I',t);
  EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = current_setting(''app.tenant_id'', true)) WITH CHECK (tenant_id = current_setting(''app.tenant_id'', true))',t);
  EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON %I TO autoflow_crm',t);
 END LOOP;
END $$;
