DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_pricing') THEN
        CREATE ROLE autoflow_pricing
            LOGIN
            NOSUPERUSER
            NOBYPASSRLS
            PASSWORD 'local-only-pricing-password';
    END IF;
END
$$;

GRANT CONNECT ON DATABASE autoflow TO autoflow_pricing;
GRANT USAGE ON SCHEMA public TO autoflow_pricing;
