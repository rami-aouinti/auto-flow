# Contract / Billing / Document Service — production readiness

## Ownership and request flow

- Contract owns the contract aggregate, immutable legal snapshot, lifecycle, DocuSign envelope and audit trail. It generates the PDF, uploads it to Document Service, and stores the returned Document UUID and checksum. Its private local copy is retained for the DocuSign adapter only.
- Billing owns invoice and credit-note aggregates, numbering, tax breakdowns and immutable issuer/recipient snapshots. It lazily renders each PDF, uploads it to Document Service and stores the Document UUID/checksum in `billing_document_references`.
- Document Service owns canonical binary storage, malware scanning, metadata, versions, tenant isolation, encrypted object storage and retention. Contract and Billing proxy downloads through Document Service and independently verify SHA-256.

The browser never supplies a Document owner or legal snapshot. The forwarded Keycloak bearer token is verified by Document Service; the authenticated subject becomes the document owner.

## Local end-to-end verification

Use a Keycloak user whose access token has the relevant service audience(s), `documents:write`, `documents:read`, and the business permission. The demo and order-operator realm users have these local roles. Existing Keycloak realms must be updated with the Document audience mapper and roles, then obtain a fresh token.

Start the dependencies and services:

```bash
docker compose --env-file .env.example up -d --build \
  postgres postgres-bootstrap rabbitmq clamav \
  document-object-storage document-object-storage-init \
  document-service document-outbox-publisher document-retention-worker \
  order-service contract-service billing-service billing-payment-event-consumer api-gateway
```

Check readiness and API contracts:

```bash
curl -fsS http://localhost:8100/health/ready
curl -fsS http://localhost:8090/health/ready
curl -fsS http://localhost:8087/health/ready
curl -fsS http://localhost:8100/api/doc.json | jq -e '.paths["/api/v1/documents"].post'
docker compose --env-file .env.example config --quiet
```

With a prepared Contract UUID and a legally complete issued invoice UUID:

```bash
export DOCUMENT_E2E_TOKEN='<fresh Keycloak access token>'
export CONTRACT_E2E_CONTRACT_ID='<prepared contract UUID>'
export BILLING_E2E_INVOICE_ID='<issued invoice UUID>'

# The scripts run inside their service containers and call the API Gateway.
docker compose --env-file .env.example exec -T \
  -e DOCUMENT_E2E_TOKEN -e CONTRACT_E2E_CONTRACT_ID \
  contract-service php bin/smoke-test-document-integration.php

docker compose --env-file .env.example exec -T \
  -e DOCUMENT_E2E_TOKEN -e BILLING_E2E_INVOICE_ID \
  billing-service php bin/smoke-test-document-integration.php
```

To include a credit note, also set `BILLING_E2E_CREDIT_NOTE_ID` and pass it to the Billing container. The smoke tests verify canonical Document Service metadata, PDF signatures, repeated-request idempotency and SHA-256 equality. The Contract test requires a contract already in `prepared` state; the Billing test requires a legally complete `issued` invoice. These are integration smoke tests, not a substitute for the full business journey from order acceptance and payment capture.

## Production deployment gates

1. **Identity:** Configure the production Keycloak clients/audience mapper so user access tokens contain `autoflow-contract` or `billing` plus `autoflow-document`. Grant only the required `documents:write` and `documents:read` permissions to the business roles. Test cross-tenant access denial.
2. **Network:** Keep Document Service private; expose it only to trusted application services. Set `DOCUMENT_SERVICE_URL` to its private endpoint. Do not expose MinIO/RustFS administration publicly.
3. **Object storage:** Use a managed S3-compatible service over HTTPS, a dedicated least-privilege identity, private bucket policy, versioning/backup appropriate to the retention policy, and a protected 32-byte SSE-C key. Keep the encryption key stable and backed up securely; define and rehearse key rotation and restore procedures before production.
4. **Malware scanning:** Run a highly available ClamAV service. Uploads must fail closed when scanning is unavailable. Alert on scan failures and S3 5xx/timeout responses.
5. **Database:** Apply migration `173-billing-document-references.sql` through `postgres-bootstrap`. Verify RLS is enabled and forced on `billing_document_references`. Back up PostgreSQL and object storage consistently.
6. **Legal / tax:** Review contract templates, jurisdiction clauses, invoice and credit-note fields, numbering, VAT treatment and retention requirements with qualified legal/tax professionals. Replace all issuer placeholders. A local 19% VAT default is not a production tax engine.
7. **Signing:** Configure the real production DocuSign account, JWT impersonation consent, RSA private key and Connect HMAC secret. Verify a signed callback and provider reconciliation before customer signature traffic.
8. **Operations:** Configure alerts for Document Service readiness, malware scanner outage, object-storage errors, failed Contract/Billing document requests, outbox retries and retention-worker failures. Test restore, retention and legal-hold behavior.

Production configuration validation:

```bash
docker compose -f compose.yaml -f compose.production.yaml \
  --env-file .env.production config --quiet
```

Do not deploy with values from `.env.example` or `.env.production.example`. Do not run `docker compose down -v` against a database containing data.

## Current validation boundary

The repository now contains Contract and Billing Document Service clients, persistent reference metadata, invoice and credit-note PDF routes, migrations, API documentation and repeatable end-to-end smoke scripts. GitHub Actions performs PHP syntax and migration/Compose wiring checks. A production sign-off still requires executing the end-to-end scripts against the target environment, verifying the real Keycloak permissions and managed S3/ClamAV setup, and completing the legal/tax and DocuSign gates above.
