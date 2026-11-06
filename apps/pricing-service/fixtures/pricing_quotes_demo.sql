-- Deterministic local/demo policy and quote fixtures.
-- Run with psql variables: tenant_id and created_by (the local Keycloak demo user's sub).
-- Example:
-- docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U autoflow -d autoflow \
--   -v tenant_id=tenant-demo -v created_by="$DEMO_SUBJECT" < apps/pricing-service/fixtures/pricing_quotes_demo.sql

BEGIN;
SELECT set_config('app.tenant_id', :'tenant_id', true);

INSERT INTO pricing_policies (
    policy_id, tenant_id, version, status,
    minimum_duration_months, maximum_duration_months,
    minimum_annual_rate_bps, maximum_annual_rate_bps,
    quote_validity_days, calculation_version, created_by, published_at
) VALUES (
    '123e4567-e89b-42d3-a456-426614174000', :'tenant_id', 1, 'active',
    12, 72, 0, 2000, 7, 'lease-annuity-v1', :'created_by', CURRENT_TIMESTAMP
)
ON CONFLICT (policy_id) DO UPDATE SET
    status = 'active',
    published_at = COALESCE(pricing_policies.published_at, EXCLUDED.published_at);

INSERT INTO pricing_quotes (
    quote_id, tenant_id, created_by, vehicle_id,
    monthly_amount_minor, total_installments_minor, total_cost_minor,
    residual_value_minor, currency, duration_months,
    annual_interest_rate_basis_points, calculation_version,
    vehicle_price_minor, down_payment_minor, expires_at,
    policy_id, policy_version
) VALUES
('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', :'tenant_id', :'created_by', '40000000000000000000000000000004', 68638, 3294624, 3794624, 800000, 'EUR', 48, 425, 'lease-annuity-v1', 4200000, 500000, CURRENT_TIMESTAMP + INTERVAL '7 days', '123e4567-e89b-42d3-a456-426614174000', 1),
('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', :'tenant_id', :'created_by', '40000000000000000000000000000003', 58935, 2828880, 3328880, 950000, 'EUR', 48, 425, 'lease-annuity-v1', 3899000, 500000, CURRENT_TIMESTAMP + INTERVAL '7 days', '123e4567-e89b-42d3-a456-426614174000', 1),
('cccccccccccccccccccccccccccccccc', :'tenant_id', :'created_by', '40000000000000000000000000000005', 58801, 2822448, 3222448, 720000, 'EUR', 48, 425, 'lease-annuity-v1', 3599000, 400000, CURRENT_TIMESTAMP + INTERVAL '7 days', '123e4567-e89b-42d3-a456-426614174000', 1),
('dddddddddddddddddddddddddddddddd', :'tenant_id', :'created_by', '40000000000000000000000000000007', 67657, 3247536, 3747536, 850000, 'EUR', 48, 425, 'lease-annuity-v1', 4199000, 500000, CURRENT_TIMESTAMP + INTERVAL '7 days', '123e4567-e89b-42d3-a456-426614174000', 1),
('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', :'tenant_id', :'created_by', '40000000000000000000000000000006', 75805, 3638640, 4138640, 780000, 'EUR', 48, 425, 'lease-annuity-v1', 4499000, 500000, CURRENT_TIMESTAMP + INTERVAL '7 days', '123e4567-e89b-42d3-a456-426614174000', 1),
('ffffffffffffffffffffffffffffffff', :'tenant_id', :'created_by', '40000000000000000000000000000001', 34145, 1638960, 2138960, 1200000, 'EUR', 48, 450, 'lease-annuity-v1', 3000000, 500000, CURRENT_TIMESTAMP + INTERVAL '7 days', '123e4567-e89b-42d3-a456-426614174000', 1),
('77777777777777777777777777777777', :'tenant_id', :'created_by', '40000000000000000000000000000002', 41745, 1502820, 1902820, 750000, 'EUR', 36, 350, 'lease-annuity-v1', 2500000, 400000, CURRENT_TIMESTAMP + INTERVAL '7 days', '123e4567-e89b-42d3-a456-426614174000', 1)
ON CONFLICT (quote_id) DO UPDATE SET
    tenant_id = EXCLUDED.tenant_id,
    created_by = EXCLUDED.created_by,
    vehicle_id = EXCLUDED.vehicle_id,
    monthly_amount_minor = EXCLUDED.monthly_amount_minor,
    total_installments_minor = EXCLUDED.total_installments_minor,
    total_cost_minor = EXCLUDED.total_cost_minor,
    residual_value_minor = EXCLUDED.residual_value_minor,
    currency = EXCLUDED.currency,
    duration_months = EXCLUDED.duration_months,
    annual_interest_rate_basis_points = EXCLUDED.annual_interest_rate_basis_points,
    calculation_version = EXCLUDED.calculation_version,
    vehicle_price_minor = EXCLUDED.vehicle_price_minor,
    down_payment_minor = EXCLUDED.down_payment_minor,
    expires_at = EXCLUDED.expires_at,
    policy_id = EXCLUDED.policy_id,
    policy_version = EXCLUDED.policy_version,
    deleted_at = NULL;

COMMIT;
