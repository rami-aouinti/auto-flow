SET search_path TO document_service, public;
INSERT INTO documents (
 id, tenant_id, owner_id, category, title, description, original_filename, mime_type,
 byte_size, checksum_sha256, storage_key, scan_status, status, metadata
) VALUES
 ('d0c00000-0000-4000-8000-000000000001','tenant-demo','demo-user','contract','Demo leasing contract','Sample metadata record; no binary is attached.','leasing-contract-demo.pdf','application/pdf',1024,repeat('a',64),'fixtures/demo-contract.pdf','clean','active','{"demo":true,"source":"fixture"}'::jsonb),
 ('d0c00000-0000-4000-8000-000000000002','tenant-demo','demo-user','identity','Demo identity document','Sample metadata record for the customer portal.','identity-demo.pdf','application/pdf',2048,repeat('b',64),'fixtures/demo-identity.pdf','clean','active','{"demo":true,"source":"fixture"}'::jsonb)
ON CONFLICT (id) DO NOTHING;
