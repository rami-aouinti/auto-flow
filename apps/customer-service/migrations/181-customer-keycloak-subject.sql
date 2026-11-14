ALTER TABLE customer_records
    ADD COLUMN IF NOT EXISTS keycloak_subject VARCHAR(255);

CREATE UNIQUE INDEX IF NOT EXISTS uq_customer_tenant_keycloak_subject
    ON customer_records (tenant_id, keycloak_subject)
    WHERE keycloak_subject IS NOT NULL AND archived_at IS NULL;

COMMENT ON COLUMN customer_records.keycloak_subject IS
    'Verified Keycloak subject mapped to this customer; never supplied by the customer self-service request.';
