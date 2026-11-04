CREATE OR REPLACE FUNCTION prevent_pricing_policy_version_mutation()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF ROW(
        NEW.policy_id, NEW.tenant_id, NEW.version,
        NEW.minimum_duration_months, NEW.maximum_duration_months,
        NEW.minimum_annual_rate_bps, NEW.maximum_annual_rate_bps,
        NEW.quote_validity_days, NEW.calculation_version,
        NEW.created_by, NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.policy_id, OLD.tenant_id, OLD.version,
        OLD.minimum_duration_months, OLD.maximum_duration_months,
        OLD.minimum_annual_rate_bps, OLD.maximum_annual_rate_bps,
        OLD.quote_validity_days, OLD.calculation_version,
        OLD.created_by, OLD.created_at
    ) THEN
        RAISE EXCEPTION 'Pricing policy versions are immutable';
    END IF;

    IF OLD.status = 'draft' AND NEW.status NOT IN ('draft', 'active') THEN
        RAISE EXCEPTION 'Invalid pricing policy lifecycle transition';
    ELSIF OLD.status = 'active' AND NEW.status NOT IN ('active', 'retired') THEN
        RAISE EXCEPTION 'Invalid pricing policy lifecycle transition';
    ELSIF OLD.status = 'retired' AND NEW.status <> 'retired' THEN
        RAISE EXCEPTION 'Retired pricing policies cannot be reactivated';
    END IF;

    IF OLD.published_at IS NOT NULL AND NEW.published_at IS DISTINCT FROM OLD.published_at THEN
        RAISE EXCEPTION 'Pricing policy publication timestamp is immutable';
    END IF;
    IF OLD.status = 'draft' AND NEW.status = 'active' AND NEW.published_at IS NULL THEN
        RAISE EXCEPTION 'Published pricing policies require published_at';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS pricing_policies_immutable_version ON pricing_policies;
CREATE TRIGGER pricing_policies_immutable_version
    BEFORE UPDATE ON pricing_policies
    FOR EACH ROW
    EXECUTE FUNCTION prevent_pricing_policy_version_mutation();
