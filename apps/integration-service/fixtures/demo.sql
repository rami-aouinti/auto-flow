INSERT INTO integration_service.webhook_events(tenant_id,provider,external_event_id,event_type,payload) VALUES
('tenant-demo','demo-provider','evt_demo_001','demo.ping','{"message":"Integration webhook fixture"}')
ON CONFLICT (tenant_id,provider,external_event_id) DO NOTHING;
