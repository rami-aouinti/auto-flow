ALTER TABLE customer_records
    ADD COLUMN IF NOT EXISTS keycloak_subject VARCHAR(255);

CREATE UNIQUE INDEX IF NOT EXISTS uq_customer_tenant_keycloak_subject
    ON customer_records (tenant_id, keycloak_subject)
    WHERE keycloak_subject IS NOT NULL AND archived_at IS NULL;

COMMENT ON COLUMN customer_records.keycloak_subject IS
    'Keycloak subject linked by a tenant administrator after identity verification; never supplied by the self-service request.';
