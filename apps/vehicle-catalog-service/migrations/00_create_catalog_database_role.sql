DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_catalog') THEN
        CREATE ROLE autoflow_catalog LOGIN PASSWORD 'local-only-catalog-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
    END IF;
END
$$;
GRANT CONNECT ON DATABASE autoflow TO autoflow_catalog;
GRANT USAGE ON SCHEMA public TO autoflow_catalog;