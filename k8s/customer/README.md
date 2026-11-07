# Customer Service on Kubernetes

Build the image from the repository root:

```bash
docker build -f docker/php/service.Dockerfile --build-arg SERVICE_PATH=apps/customer-service --build-arg COMPOSER_NO_DEV=--no-dev -t autoflow/customer-service:local .
```

Create the namespace if needed, then create a real Secret from the example. Never apply placeholder credentials:

```bash
kubectl create namespace autoflow --dry-run=client -o yaml | kubectl apply -f -
cp k8s/customer/secret.example.yaml /tmp/customer-secret.yaml
# Edit /tmp/customer-secret.yaml with the real APP_SECRET and CUSTOMER_DATABASE_URL.
kubectl apply -f /tmp/customer-secret.yaml
kubectl apply -f k8s/customer/configmap.yaml
kubectl apply -f k8s/customer/deployment.yaml
kubectl apply -f k8s/customer/service.yaml
kubectl rollout status deployment/customer-service -n autoflow
kubectl get pods,svc -n autoflow -l app=customer-service
```

Provision the PostgreSQL role and schema before the pods become ready. Use an external secret manager in production.
