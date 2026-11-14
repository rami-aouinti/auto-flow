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
