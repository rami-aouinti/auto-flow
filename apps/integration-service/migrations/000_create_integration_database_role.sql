DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='autoflow_integration') THEN
    CREATE ROLE autoflow_integration LOGIN PASSWORD 'local-only-integration-password';
  END IF;
END $$;
GRANT CONNECT ON DATABASE autoflow TO autoflow_integration;
GRANT USAGE,CREATE ON SCHEMA integration_service TO autoflow_integration;
GRANT SELECT,INSERT,UPDATE ON ALL TABLES IN SCHEMA integration_service TO autoflow_integration;
GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA integration_service TO autoflow_integration;
ALTER DEFAULT PRIVILEGES IN SCHEMA integration_service GRANT SELECT,INSERT,UPDATE ON TABLES TO autoflow_integration;
ALTER DEFAULT PRIVILEGES IN SCHEMA integration_service GRANT USAGE,SELECT ON SEQUENCES TO autoflow_integration;
