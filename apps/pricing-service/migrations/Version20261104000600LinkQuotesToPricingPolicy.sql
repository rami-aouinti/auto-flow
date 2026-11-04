ALTER TABLE pricing_quotes
    ADD COLUMN IF NOT EXISTS policy_id UUID NULL,
    ADD COLUMN IF NOT EXISTS policy_version INTEGER NULL;

ALTER TABLE pricing_quotes
    ADD CONSTRAINT pricing_quotes_policy_pair
        CHECK ((policy_id IS NULL AND policy_version IS NULL) OR (policy_id IS NOT NULL AND policy_version IS NOT NULL AND policy_version > 0));

ALTER TABLE pricing_quotes
    ADD CONSTRAINT pricing_quotes_policy_fk
        FOREIGN KEY (tenant_id, policy_id) REFERENCES pricing_policies (tenant_id, policy_id);
