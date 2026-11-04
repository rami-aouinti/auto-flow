CREATE OR REPLACE FUNCTION prevent_pricing_quote_snapshot_mutation()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF ROW(
        NEW.quote_id, NEW.tenant_id, NEW.created_by, NEW.vehicle_id,
        NEW.monthly_amount_minor, NEW.total_installments_minor, NEW.total_cost_minor,
        NEW.residual_value_minor, NEW.currency, NEW.duration_months,
        NEW.annual_interest_rate_basis_points, NEW.calculation_version,
        NEW.vehicle_price_minor, NEW.down_payment_minor, NEW.expires_at,
        NEW.policy_id, NEW.policy_version, NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.quote_id, OLD.tenant_id, OLD.created_by, OLD.vehicle_id,
        OLD.monthly_amount_minor, OLD.total_installments_minor, OLD.total_cost_minor,
        OLD.residual_value_minor, OLD.currency, OLD.duration_months,
        OLD.annual_interest_rate_basis_points, OLD.calculation_version,
        OLD.vehicle_price_minor, OLD.down_payment_minor, OLD.expires_at,
        OLD.policy_id, OLD.policy_version, OLD.created_at
    ) THEN
        RAISE EXCEPTION 'Pricing quote snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS pricing_quotes_immutable_snapshot ON pricing_quotes;
CREATE TRIGGER pricing_quotes_immutable_snapshot
    BEFORE UPDATE ON pricing_quotes
    FOR EACH ROW
    EXECUTE FUNCTION prevent_pricing_quote_snapshot_mutation();
