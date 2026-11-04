DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_offer') THEN
        CREATE ROLE autoflow_offer
            LOGIN
            NOSUPERUSER
            NOBYPASSRLS
            PASSWORD 'local-only-offer-password';
    END IF;
END
$$;

GRANT CONNECT ON DATABASE autoflow TO autoflow_offer;
GRANT USAGE ON SCHEMA public TO autoflow_offer;
