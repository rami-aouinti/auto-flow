# Contract Service on Kubernetes

The manifests assume the `autoflow` namespace and in-cluster PostgreSQL, RabbitMQ, Order Service, Keycloak and Tempo Services already exist.

## Build the local image

From the repository root:

```bash
docker build -f docker/php/service.Dockerfile \
  --build-arg SERVICE_PATH=apps/contract-service \
  --build-arg COMPOSER_NO_DEV=--no-dev \
  -t autoflow/contract-service:local .
```

## Prepare secrets and storage

1. Create the namespace if it does not exist.
2. Create a real `contract-secret` from `secret.example.yaml`.
3. Create `contract-publisher-secret` from `publisher-secret.example.yaml`.
4. Create `docusign-rsa-key` from the actual private key; never apply the placeholder example.
5. Configure `rabbitmq-secret` to match the existing RabbitMQ installation.
6. Ensure the cluster has a StorageClass that supports `ReadWriteOnce` for the PDF PVC. The single Contract API replica is intentional for the filesystem-backed first version.

## Apply

```bash
kubectl apply -f k8s/contract/pvc.yaml
kubectl apply -f k8s/contract/configmap.yaml
kubectl apply -f k8s/contract/deployment.yaml
kubectl apply -f k8s/contract/service.yaml
kubectl apply -f k8s/contract/outbox-publisher-deployment.yaml
kubectl apply -f k8s/contract/network-policy.yaml
kubectl rollout status deployment/contract-service -n autoflow
kubectl rollout status deployment/contract-outbox-publisher -n autoflow
```

Create the Kubernetes Secrets separately from a secret manager or a protected local file. Do not commit populated Secret manifests.

## Operational constraints

- The document PVC must be backed by durable storage. The first implementation deliberately runs one API replica with a ReadWriteOnce claim. For multiple API replicas or multi-region deployments, replace the filesystem adapter with S3-compatible object storage and immutable object versioning.
- The DocuSign private key is mounted read-only at `/var/run/secrets/docusign/private.pem`.
- Set the DocuSign Connect HMAC key in `DOCUSIGN_WEBHOOK_HMAC_KEY` and configure the Connect listener URL as `/api/v1/contracts/webhooks/docusign`.
- NetworkPolicy selectors assume the listed pod labels; adapt them to the labels used by the actual PostgreSQL, RabbitMQ, Keycloak, Tempo and gateway deployments.
- The DocuSign example configuration uses the demo environment. Production requires the production OAuth host and the account-specific API base URI.
