DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_order') THEN
        CREATE ROLE autoflow_order LOGIN PASSWORD 'local-only-order-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$;
ALTER ROLE autoflow_order WITH LOGIN PASSWORD 'local-only-order-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_order;
GRANT USAGE ON SCHEMA public TO autoflow_order;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_order_publisher') THEN
        CREATE ROLE autoflow_order_publisher LOGIN PASSWORD 'local-only-order-publisher-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$;
ALTER ROLE autoflow_order_publisher WITH LOGIN PASSWORD 'local-only-order-publisher-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_order_publisher;
GRANT USAGE ON SCHEMA public TO autoflow_order_publisher;
