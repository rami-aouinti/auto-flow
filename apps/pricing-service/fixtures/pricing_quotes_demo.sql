-- Deterministic local/demo policy and quote fixtures for Swagger and API smoke tests.
-- Execute inside a transaction with the matching tenant context.
BEGIN;
SELECT set_config('app.tenant_id', 'tenant-demo', true);

INSERT INTO pricing_policies (
    policy_id, tenant_id, version, status,
    minimum_duration_months, maximum_duration_months,
    minimum_annual_rate_bps, maximum_annual_rate_bps,
    quote_validity_days, calculation_version, created_by, published_at
) VALUES (
    '123e4567-e89b-42d3-a456-426614174000', 'tenant-demo', 1, 'draft',
    12, 72, 0, 2000, 7, 'lease-annuity-v1', 'policy-admin-demo', NULL
)
ON CONFLICT (policy_id) DO NOTHING;

UPDATE pricing_policies
SET status = 'active', published_at = CURRENT_TIMESTAMP
WHERE policy_id = '123e4567-e89b-42d3-a456-426614174000' AND status = 'draft';

INSERT INTO pricing_quotes (
    quote_id, tenant_id, created_by, vehicle_id,
    monthly_amount_minor, total_installments_minor, total_cost_minor,
    residual_value_minor, currency, duration_months,
    annual_interest_rate_basis_points, calculation_version,
    vehicle_price_minor, down_payment_minor, expires_at,
    policy_id, policy_version
) VALUES
('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'tenant-demo', 'swagger-demo-user', 'vehicle-demo-001', 45546, 2186208, 2686208, 1200000, 'EUR', 48, 450, 'lease-annuity-v1', 3000000, 500000, CURRENT_TIMESTAMP + INTERVAL '7 days', '123e4567-e89b-42d3-a456-426614174000', 1),
('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'tenant-demo', 'swagger-demo-user', 'vehicle-demo-002', 38990, 1403640, 1703640, 750000, 'EUR', 36, 350, 'lease-annuity-v1', 2500000, 400000, CURRENT_TIMESTAMP + INTERVAL '7 days', '123e4567-e89b-42d3-a456-426614174000', 1)
ON CONFLICT (quote_id) DO NOTHING;
COMMIT;
