DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_payment') THEN
        CREATE ROLE autoflow_payment LOGIN PASSWORD 'local-only-payment-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$;
ALTER ROLE autoflow_payment WITH LOGIN PASSWORD 'local-only-payment-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_payment;
GRANT USAGE ON SCHEMA public TO autoflow_payment;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoflow_payment_publisher') THEN
        CREATE ROLE autoflow_payment_publisher LOGIN PASSWORD 'local-only-payment-publisher-password'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$;
ALTER ROLE autoflow_payment_publisher WITH LOGIN PASSWORD 'local-only-payment-publisher-password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE autoflow TO autoflow_payment_publisher;
GRANT USAGE ON SCHEMA public TO autoflow_payment_publisher;
