# Billing Kubernetes deployment

Build from the repository root:

```bash
docker build -f docker/php/service.Dockerfile --build-arg SERVICE_PATH=apps/billing-service -t autoflow/billing-service:local .
```

Copy `secret.example.yaml` to `secret.yaml`, replace every placeholder, and ensure the billing SQL role/schema has been applied to PostgreSQL. Then deploy:

```bash
kubectl apply -f k8s/billing/configmap.yaml
kubectl apply -f k8s/billing/secret.yaml
kubectl apply -f k8s/billing/deployment.yaml
kubectl apply -f k8s/billing/service.yaml
kubectl apply -f k8s/billing/event-consumer-deployment.yaml
kubectl -n autoflow rollout status deployment/billing-service
kubectl -n autoflow get pods -l app=billing-service
```

The HTTP API and RabbitMQ consumer are separate Deployments. Keep the consumer at one replica until event ordering and idempotency behavior have been load-tested.
