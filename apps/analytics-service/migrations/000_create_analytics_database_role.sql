DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='autoflow_analytics') THEN
    CREATE ROLE autoflow_analytics LOGIN PASSWORD 'local-only-analytics-password';
  END IF;
END $$;
GRANT CONNECT ON DATABASE autoflow TO autoflow_analytics;
GRANT USAGE,CREATE ON SCHEMA analytics_service TO autoflow_analytics;
GRANT SELECT,INSERT ON ALL TABLES IN SCHEMA analytics_service TO autoflow_analytics;
GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA analytics_service TO autoflow_analytics;
ALTER DEFAULT PRIVILEGES IN SCHEMA analytics_service GRANT SELECT,INSERT ON TABLES TO autoflow_analytics;
ALTER DEFAULT PRIVILEGES IN SCHEMA analytics_service GRANT USAGE,SELECT ON SEQUENCES TO autoflow_analytics;
