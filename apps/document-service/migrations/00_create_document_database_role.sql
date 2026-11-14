DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_document') THEN
        CREATE ROLE autoflow_document LOGIN PASSWORD 'local-only-document-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
    END IF;
END
$$;
GRANT CONNECT ON DATABASE autoflow TO autoflow_document;
