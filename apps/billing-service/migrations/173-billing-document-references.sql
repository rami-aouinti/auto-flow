-- Billing keeps the invoice aggregate and its immutable reference; Document Service owns PDF bytes.
CREATE TABLE IF NOT EXISTS billing_document_references (
    tenant_id VARCHAR(128) NOT NULL,
    entity_type VARCHAR(32) NOT NULL CHECK (entity_type IN ('invoice')),
    entity_id UUID NOT NULL,
    document_id UUID NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL CHECK (checksum_sha256 ~ '^[a-f0-9]{64}$'),
    byte_size BIGINT NOT NULL CHECK (byte_size > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, entity_type, entity_id),
    UNIQUE (tenant_id, document_id),
    FOREIGN KEY (tenant_id, entity_id) REFERENCES billing_invoices (tenant_id, invoice_id) ON DELETE RESTRICT
);
ALTER TABLE billing_document_references ENABLE ROW LEVEL SECURITY;
ALTER TABLE billing_document_references FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS billing_document_references_tenant_isolation ON billing_document_references;
CREATE POLICY billing_document_references_tenant_isolation ON billing_document_references
    USING (tenant_id = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true));
GRANT SELECT, INSERT ON billing_document_references TO autoflow_billing;
