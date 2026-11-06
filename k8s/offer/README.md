# Offer Service on Kubernetes

These manifests target Docker Desktop Kubernetes and the locally built image `autoflow/offer-service:local`.

## Build and tag

From the repository root:

```bash
docker compose build offer-service
docker compose images offer-service
```

Tag the built image as `autoflow/offer-service:local` if Compose assigned another image name.

## Prepare credentials

```bash
cp k8s/offer/secret.example.yaml k8s/offer/secret.yaml
# Edit secret.yaml and replace every placeholder.
kubectl apply -f k8s/pricing/namespace.yaml
kubectl apply -f k8s/offer/configmap.yaml
kubectl apply -f k8s/offer/secret.yaml
```

Do not commit `secret.yaml`; it is ignored by Git.

## Deploy

```bash
kubectl apply -f k8s/offer/deployment.yaml
kubectl apply -f k8s/offer/service.yaml
kubectl apply -f k8s/offer/outbox-publisher-deployment.yaml
kubectl apply -f k8s/offer/expire-offers-cronjob.yaml
kubectl -n autoflow rollout status deployment/offer-service
kubectl -n autoflow rollout status deployment/offer-outbox-publisher
kubectl -n autoflow get pods,services,cronjobs
```

The expiration CronJob runs once per minute, forbids overlapping executions, retries failed Jobs and retains a small job history. It invokes `php bin/expire-offers.php --once`. Docker Compose instead runs the long-lived expiration loop under Supervisor. Do not deploy both modes against the same environment.
