CREATE SCHEMA IF NOT EXISTS integration_service;
CREATE TABLE IF NOT EXISTS integration_service.webhook_events (
  id BIGSERIAL PRIMARY KEY,
  tenant_id TEXT NOT NULL,
  provider TEXT NOT NULL,
  external_event_id TEXT NOT NULL,
  event_type TEXT NOT NULL,
  payload JSONB NOT NULL,
  received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (tenant_id,provider,external_event_id)
);
CREATE TABLE IF NOT EXISTS integration_service.delivery_attempts (
  id UUID PRIMARY KEY,
  tenant_id TEXT NOT NULL,
  provider TEXT NOT NULL,
  target_url TEXT NOT NULL,
  event_type TEXT NOT NULL,
  payload JSONB NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('pending','delivered','failed')),
  attempts INTEGER NOT NULL DEFAULT 0,
  next_attempt_at TIMESTAMPTZ,
  last_error TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS integration_delivery_due_idx ON integration_service.delivery_attempts(status,next_attempt_at);
ALTER TABLE integration_service.webhook_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE integration_service.webhook_events FORCE ROW LEVEL SECURITY;
ALTER TABLE integration_service.delivery_attempts ENABLE ROW LEVEL SECURITY;
ALTER TABLE integration_service.delivery_attempts FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS integration_webhooks_tenant_policy ON integration_service.webhook_events;
CREATE POLICY integration_webhooks_tenant_policy ON integration_service.webhook_events
 USING (tenant_id=current_setting('app.tenant_id',true))
 WITH CHECK (tenant_id=current_setting('app.tenant_id',true));
DROP POLICY IF EXISTS integration_deliveries_tenant_policy ON integration_service.delivery_attempts;
CREATE POLICY integration_deliveries_tenant_policy ON integration_service.delivery_attempts
 USING (tenant_id=current_setting('app.tenant_id',true))
 WITH CHECK (tenant_id=current_setting('app.tenant_id',true));
