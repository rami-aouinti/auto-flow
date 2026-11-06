DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_payment') THEN
        CREATE ROLE autoflow_payment LOGIN PASSWORD 'local-only-payment-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$$;
GRANT CONNECT ON DATABASE autoflow TO autoflow_payment;
GRANT USAGE ON SCHEMA public TO autoflow_payment;
