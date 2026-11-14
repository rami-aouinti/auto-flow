DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_configuration') THEN
        CREATE ROLE autoflow_configuration LOGIN PASSWORD 'local-only-configuration-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
    END IF;
END
$$;
GRANT CONNECT ON DATABASE autoflow TO autoflow_configuration;
