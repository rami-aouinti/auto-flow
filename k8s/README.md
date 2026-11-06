# AutoFlow Kubernetes workloads

Apply the namespace first: `kubectl apply -f k8s/pricing/namespace.yaml`.

- `pricing/`: existing Pricing Service example.
- `offer/`: Offer API, Outbox publisher and scheduled expiration CronJob.
- `vehicle-catalog/`: Vehicle Catalog API and Outbox publisher.

These manifests target Docker Desktop Kubernetes. Images use `imagePullPolicy: Never` and must already exist in Docker Desktop's image store. Secret files are templates only. For real clusters, use an external secret manager, in-cluster PostgreSQL/RabbitMQ/Keycloak Services, TLS, NetworkPolicies and a private image registry.

Kubernetes Deployments supervise long-running containers and restart failed Pods. Supervisor is therefore used by the Docker Compose Offer worker container, not nested inside Kubernetes Pods. The Offer expiration CronJob is a one-shot Job, not a permanent polling loop.
