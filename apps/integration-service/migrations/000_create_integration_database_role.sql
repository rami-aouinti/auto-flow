DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='autoflow_integration') THEN
    CREATE ROLE autoflow_integration LOGIN PASSWORD 'local-only-integration-password';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='autoflow_integration_worker') THEN
    CREATE ROLE autoflow_integration_worker LOGIN BYPASSRLS PASSWORD 'local-only-integration-worker-password';
  END IF;
END $;
CREATE SCHEMA IF NOT EXISTS integration_service;
GRANT CONNECT ON DATABASE autoflow TO autoflow_integration;
GRANT USAGE,CREATE ON SCHEMA integration_service TO autoflow_integration;
GRANT SELECT,INSERT,UPDATE ON ALL TABLES IN SCHEMA integration_service TO autoflow_integration;
GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA integration_service TO autoflow_integration;
ALTER DEFAULT PRIVILEGES IN SCHEMA integration_service GRANT SELECT,INSERT,UPDATE ON TABLES TO autoflow_integration;
ALTER DEFAULT PRIVILEGES IN SCHEMA integration_service GRANT USAGE,SELECT ON SEQUENCES TO autoflow_integration;
GRANT CONNECT ON DATABASE autoflow TO autoflow_integration_worker;
GRANT USAGE ON SCHEMA integration_service TO autoflow_integration_worker;
GRANT SELECT,UPDATE ON ALL TABLES IN SCHEMA integration_service TO autoflow_integration_worker;
ALTER DEFAULT PRIVILEGES IN SCHEMA integration_service GRANT SELECT,UPDATE ON TABLES TO autoflow_integration_worker;
