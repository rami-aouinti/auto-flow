SET search_path TO configuration_service, public;
INSERT INTO configurations
(id,tenant_id,namespace,config_key,value,value_type,description,version,created_by,updated_by)
VALUES
('c0f10000-0000-4000-8000-000000000001','tenant-demo','checkout','allow_guest_checkout','true'::jsonb,'boolean','Allow checkout without account',1,'fixture','fixture'),
('c0f10000-0000-4000-8000-000000000002','tenant-demo','checkout','default_currency','"EUR"'::jsonb,'string','Default storefront currency',1,'fixture','fixture'),
('c0f10000-0000-4000-8000-000000000003','tenant-demo','notifications','channels','["email","sms"]'::jsonb,'array','Enabled notification channels',1,'fixture','fixture')
ON CONFLICT (tenant_id,namespace,config_key) DO NOTHING;

INSERT INTO configuration_versions
(id,tenant_id,namespace,config_key,version,value,value_type,description,changed_by,change_reason)
SELECT gen_random_uuid(),tenant_id,namespace,config_key,version,value,value_type,description,'fixture','Initial demo configuration'
FROM configurations WHERE tenant_id='tenant-demo'
ON CONFLICT (tenant_id,namespace,config_key,version) DO NOTHING;

INSERT INTO feature_flags
(id,tenant_id,flag_key,enabled,rollout_percentage,tenant_allowlist,description,version,created_by,updated_by)
VALUES
('c0f10000-0000-4000-8000-000000000011','tenant-demo','new-checkout',true,100,'[]'::jsonb,'New checkout experience',1,'fixture','fixture'),
('c0f10000-0000-4000-8000-000000000012','tenant-demo','vehicle-recommendations',true,25,'["tenant-demo"]'::jsonb,'Vehicle recommendation module',1,'fixture','fixture'),
('c0f10000-0000-4000-8000-000000000013','tenant-demo','maintenance-mode',false,0,'[]'::jsonb,'Emergency storefront maintenance switch',1,'fixture','fixture')
ON CONFLICT (tenant_id,flag_key) DO NOTHING;
