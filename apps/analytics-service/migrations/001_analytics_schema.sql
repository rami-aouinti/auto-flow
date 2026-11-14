CREATE SCHEMA IF NOT EXISTS analytics_service;
CREATE TABLE IF NOT EXISTS analytics_service.events (
  id BIGSERIAL PRIMARY KEY,
  tenant_id TEXT NOT NULL,
  event_id TEXT NOT NULL,
  event_type TEXT NOT NULL,
  occurred_at TIMESTAMPTZ NOT NULL,
  payload JSONB NOT NULL DEFAULT '{}'::jsonb,
  received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (tenant_id,event_id)
);
CREATE INDEX IF NOT EXISTS analytics_events_tenant_time_idx ON analytics_service.events(tenant_id,occurred_at DESC);
CREATE INDEX IF NOT EXISTS analytics_events_tenant_type_idx ON analytics_service.events(tenant_id,event_type,occurred_at DESC);
