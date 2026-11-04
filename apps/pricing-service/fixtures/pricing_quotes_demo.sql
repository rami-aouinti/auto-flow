-- Deterministic local/demo quote fixtures for Swagger and API smoke tests.
-- Execute inside a transaction using the tenant context shown below.
BEGIN;
SELECT set_config('app.tenant_id', 'tenant-demo', true);
INSERT INTO pricing_quotes (
    quote_id, tenant_id, created_by, vehicle_id,
    monthly_amount_minor, total_installments_minor, total_cost_minor,
    residual_value_minor, currency, duration_months,
    annual_interest_rate_basis_points, calculation_version
) VALUES
('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'tenant-demo', 'swagger-demo-user', 'vehicle-demo-001', 45546, 2186208, 2686208, 1200000, 'EUR', 48, 450, 'lease-annuity-v1'),
('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'tenant-demo', 'swagger-demo-user', 'vehicle-demo-002', 38990, 1403640, 1703640, 750000, 'EUR', 36, 350, 'lease-annuity-v1')
ON CONFLICT (quote_id) DO NOTHING;
COMMIT;
