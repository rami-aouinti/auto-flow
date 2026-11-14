# Document Service Kubernetes deployment

The manifest at `document-service.yaml` deploys:

- Two API replicas behind the `document-service:8080` ClusterIP Service.
- Two outbox publisher replicas; row-level `SKIP LOCKED` claims prevent duplicate concurrent claims.
- One retention worker replica; the database row lock prevents simultaneous purge of the same document.
- CPU-based HPA for the API.

## Prerequisites

1. Namespace `autoflow` and PostgreSQL, RabbitMQ, ClamAV and an S3-compatible endpoint must already be available.
2. Run the Document SQL migrations, including `230-document-schema.sql` and `231-document-retention-sharing.sql`.
3. Provision a private bucket with SSE-C support and a least-privilege service identity. Use a stable 32-byte encryption key and store it in a secret manager.
4. Build and publish the Document Service image:

```bash
docker build -t ghcr.io/rami-aouinti/auto-flow-document-service:<tag> -f apps/document-service/Dockerfile apps/document-service
docker push ghcr.io/rami-aouinti/auto-flow-document-service:<tag>
```

Update all three image references in the manifest to the published immutable tag or digest before applying.

## Secrets

Create the externally managed Secret `autoflow-document-secrets` with these keys (do not commit actual values):

- `document-database-url`: application DB role URL for `autoflow_document`.
- `document-maintenance-database-url`: restricted retention maintenance role URL; it needs only the explicit Document table grants from migration 231 and BYPASSRLS.
- `s3-access-key`, `s3-secret-key`: bucket-scoped S3 credentials.
- `s3-sse-c-key-base64`: base64-encoded 32-byte SSE-C key.
- `rabbitmq-user`, `rabbitmq-password`: publisher credentials.

Example secret creation (supply values from your secret manager, not shell history in production):

```bash
kubectl -n autoflow create secret generic autoflow-document-secrets \
  --from-literal=document-database-url="$DOCUMENT_DATABASE_URL" \
  --from-literal=document-maintenance-database-url="$DOCUMENT_MAINTENANCE_DATABASE_URL" \
  --from-literal=s3-access-key="$DOCUMENT_S3_ACCESS_KEY" \
  --from-literal=s3-secret-key="$DOCUMENT_S3_SECRET_KEY" \
  --from-literal=s3-sse-c-key-base64="$DOCUMENT_S3_SSE_C_KEY_BASE64" \
  --from-literal=rabbitmq-user="$RABBITMQ_USER" \
  --from-literal=rabbitmq-password="$RABBITMQ_PASSWORD"
```

Set the ConfigMap values in `document-service.yaml` for the target cluster: S3 endpoint/bucket/region, Keycloak issuer and JWKS URI, RabbitMQ management URL and ClamAV DNS. The current example uses placeholder endpoints.

## Apply and verify

```bash
kubectl apply -f infrastructure/kubernetes/document-service.yaml
kubectl -n autoflow rollout status deployment/document-service
kubectl -n autoflow rollout status deployment/document-outbox-publisher
kubectl -n autoflow rollout status deployment/document-retention-worker
kubectl -n autoflow get pods,svc,hpa -l app.kubernetes.io/name=document-service
kubectl -n autoflow port-forward svc/document-service 8100:8080
curl -fsS http://127.0.0.1:8100/health/ready
```

The API readiness probe checks PostgreSQL, object storage and ClamAV. The retention maintenance credential is high-impact: keep it in an external secret manager, restrict network access, audit usage and periodically review grants. Use a managed S3/KMS integration and documented key-rotation/re-encryption procedure for production.
