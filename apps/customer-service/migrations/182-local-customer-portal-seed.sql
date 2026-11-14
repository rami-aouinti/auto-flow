-- Local-only demo customer account. The fixed subject matches the imported Keycloak customer-demo user.
INSERT INTO customer_records (
    tenant_id, customer_id, customer_type, first_name, last_name, email, email_normalized,
    locale, status, version, created_by, updated_by, keycloak_subject
) VALUES (
    'tenant-demo',
    '00000000-0000-4000-8000-000000000101',
    'individual',
    'Demo',
    'Customer',
    'customer-demo@autoflow.local',
    'customer-demo@autoflow.local',
    'de-DE',
    'active',
    1,
    'local-demo-seed',
    'local-demo-seed',
    '00000000-0000-4000-8000-000000000001'
) ON CONFLICT (tenant_id, customer_id) DO UPDATE
SET keycloak_subject = EXCLUDED.keycloak_subject,
    email = EXCLUDED.email,
    email_normalized = EXCLUDED.email_normalized;

INSERT INTO customer_addresses (
    tenant_id, address_id, customer_id, address_type, label, line1, postal_code, city, country_code, is_default
) VALUES (
    'tenant-demo',
    '00000000-0000-4000-8000-000000000102',
    '00000000-0000-4000-8000-000000000101',
    'residential',
    'Zuhause',
    'Demo Street 1',
    '50667',
    'Köln',
    'DE',
    TRUE
) ON CONFLICT (tenant_id, address_id) DO UPDATE
SET label = EXCLUDED.label,
    line1 = EXCLUDED.line1,
    postal_code = EXCLUDED.postal_code,
    city = EXCLUDED.city,
    country_code = EXCLUDED.country_code,
    is_default = EXCLUDED.is_default;

INSERT INTO customer_consents (tenant_id, customer_id, purpose, granted, source)
VALUES
    ('tenant-demo', '00000000-0000-4000-8000-000000000101', 'marketing.email', FALSE, 'local-demo-seed'),
    ('tenant-demo', '00000000-0000-4000-8000-000000000101', 'marketing.sms', FALSE, 'local-demo-seed')
ON CONFLICT (tenant_id, customer_id, purpose) DO UPDATE
SET granted = EXCLUDED.granted,
    source = EXCLUDED.source,
    recorded_at = CURRENT_TIMESTAMP;
