# Pricing Service on local Kubernetes

This is the first Kubernetes workload for AutoFlow. It deliberately keeps the existing
Docker Compose PostgreSQL instance and uses the Docker Desktop host gateway from the Pod.
The service starts in Symfony `dev` mode so the local Swagger routes remain available.

## 1. Build the image

Run from the repository root. Docker Desktop shares its local image store with its
Kubernetes cluster:

```bash
docker build \
  -f docker/php/service.Dockerfile \
  --build-arg SERVICE_PATH=apps/pricing-service \
  --build-arg COMPOSER_NO_DEV="" \
  -t autoflow/pricing-service:local .
```

The empty `COMPOSER_NO_DEV` build argument intentionally installs development
dependencies, including the local Swagger UI dependencies. The runtime environment is
then supplied by the Kubernetes ConfigMap.

## 2. Create the namespace and local Secret

```bash
kubectl apply -f k8s/pricing/namespace.yaml
cp k8s/pricing/secret.example.yaml k8s/pricing/secret.yaml
```

Edit `k8s/pricing/secret.yaml` and set:
- `APP_SECRET`: a random local Symfony secret.
- `DATABASE_URL`: the local PostgreSQL URL.

The default Compose database role created by this repository uses
`autoflow_pricing` / `local-only-pricing-password`. The database is exposed on the
Docker host at port 5432. The manifest uses `host.docker.internal` so the Pod can reach
that host-published port.

Apply the local Secret (the real file is git-ignored):

```bash
kubectl apply -f k8s/pricing/secret.yaml
```

Do not use the committed example Secret directly; its values are placeholders.

## 3. Deploy Pricing

```bash
kubectl apply -f k8s/pricing/configmap.yaml
kubectl apply -f k8s/pricing/deployment.yaml
kubectl apply -f k8s/pricing/service.yaml
kubectl -n autoflow rollout status deployment/pricing-service
kubectl -n autoflow get pods,deployments,services
```

The image uses `imagePullPolicy: Never` because this first exercise builds the image
locally. A remote cluster such as GKE or AKS will instead need an image registry and
an appropriate pull policy.

## 4. Test HTTP and readiness

In a second terminal:

```bash
kubectl -n autoflow port-forward service/pricing-service 8082:8080
```

Then test:

```bash
curl -i http://localhost:8082/health
curl -i http://localhost:8082/ready
curl -i http://localhost:8082/api/doc
```

Swagger is intentionally enabled only in this local learning deployment. Do not expose
the dev environment or Swagger UI publicly.

## 5. Understand the probes

- Startup probe: gives RoadRunner/Symfony time to boot before Kubernetes evaluates the
  other probes.
- Liveness probe: calls `/health`; a failed liveness check eventually restarts the
  container.
- Readiness probe: calls `/ready`; when PostgreSQL is unavailable, the Pod is removed
  from Service endpoints and stops receiving traffic.

The application README documents `/health` as liveness and `/ready` as PostgreSQL
readiness. We use those exact routes rather than guessing the CoreBundle route semantics.

## Current scope and known integration boundary

- PostgreSQL remains in Compose for this first exercise; it is not deployed as one
  database per Pod. The Pod connects to the shared host-published database.
- OpenTelemetry exporters are disabled in this first Kubernetes deployment because the
  current Tempo/Alloy endpoints are Compose-network service names and are not yet
  exposed to the Kubernetes network.
- The current Compose Keycloak port is bound to host loopback (127.0.0.1). The
  ConfigMap records the host-gateway JWKS URL, but authenticated API requests will not
  work until we deliberately connect Keycloak to the Kubernetes workload. Health,
  readiness and Swagger are the first validation scope.
- Do not scale the Deployment to multiple replicas until the single Pod is healthy and
  database connectivity has been verified.

## Useful commands

```bash
kubectl -n autoflow get pods -w
kubectl -n autoflow describe pod -l app.kubernetes.io/name=pricing-service
kubectl -n autoflow logs deployment/pricing-service --all-pods=true
kubectl -n autoflow get endpoints pricing-service
kubectl -n autoflow rollout history deployment/pricing-service
```

## Cleanup

```bash
kubectl delete namespace autoflow
```

This removes the Kubernetes workload and local Secret, but does not delete the Compose
PostgreSQL volume.
