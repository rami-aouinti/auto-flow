# Vehicle Catalog Service on Kubernetes

These manifests target Docker Desktop Kubernetes and the locally built image `autoflow/vehicle-catalog-service:local`.

## Build the image

From the repository root:

```bash
docker compose build vehicle-catalog-service
docker compose images vehicle-catalog-service
```

Tag the resulting image as `autoflow/vehicle-catalog-service:local` if Compose uses another name.

## Prepare credentials

```bash
cp k8s/vehicle-catalog/secret.example.yaml k8s/vehicle-catalog/secret.yaml
# Edit secret.yaml and replace every placeholder.
kubectl apply -f k8s/pricing/namespace.yaml
kubectl apply -f k8s/vehicle-catalog/configmap.yaml
kubectl apply -f k8s/vehicle-catalog/secret.yaml
```

Do not commit `secret.yaml`.

## Deploy

```bash
kubectl apply -f k8s/vehicle-catalog/deployment.yaml
kubectl apply -f k8s/vehicle-catalog/service.yaml
kubectl apply -f k8s/vehicle-catalog/outbox-publisher-deployment.yaml
kubectl -n autoflow rollout status deployment/vehicle-catalog-service
kubectl -n autoflow rollout status deployment/vehicle-catalog-outbox-publisher
kubectl -n autoflow get pods,services
```

The publisher is a separate Deployment so it can be restarted and scaled independently from the HTTP API. It uses a 30-second database lease and RabbitMQ publisher confirms. Delivery remains at-least-once, so event consumers must be idempotent.
